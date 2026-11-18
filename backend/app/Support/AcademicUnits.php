<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The MSU-Main colleges / units and their departments (config/academic_units.php),
 * in display order, and the one validation rule for a college + department
 * pair. Used by Create account, Add user and Edit details; the frontend gets
 * the same list from GET /api/academic-units.
 *
 * Request fields: college, department, office_name (only for "Other office /
 * unit"; stored in users.department).
 */
class AcademicUnits
{
    /**
     * Units A to Z by name, "Other office / unit" last. Each unit with
     * departments also lists "Graduate Studies" (after its own, A to Z).
     *
     * @return array<int, array{name: string, departments: array<int, string>, other: bool}>
     */
    public static function all(): array
    {
        $graduate = config('academic_units.graduate_studies');
        $units = [];
        foreach (config('academic_units.units', []) as $name => $departments) {
            sort($departments, SORT_NATURAL | SORT_FLAG_CASE);
            $units[] = [
                'name' => $name,
                'departments' => $departments ? array_merge($departments, [$graduate]) : [],
                'other' => false,
            ];
        }
        usort($units, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        $units[] = ['name' => self::otherName(), 'departments' => [], 'other' => true];

        return $units;
    }

    /** Payload for GET /api/academic-units. */
    public static function payload(): array
    {
        return [
            'units' => self::all(),
            'other' => [
                'name' => self::otherName(),
                'office_label' => config('academic_units.other.office_label'),
                'office_max' => self::officeMax(),
            ],
        ];
    }

    public static function otherName(): string
    {
        return config('academic_units.other.name');
    }

    public static function officeMax(): int
    {
        return (int) config('academic_units.other.office_max');
    }

    /** @return array{name: string, departments: array<int, string>, other: bool}|null */
    public static function find(?string $name): ?array
    {
        foreach (self::all() as $unit) {
            if ($unit['name'] === $name) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * Validate the request with $rules plus the college / department rule,
     * reporting every field error together, and return the validated data
     * with 'college' and 'department' set to the values to store.
     * $existing (Edit details): when the request has no "college" key the
     * saved values are left alone.
     *
     * @throws ValidationException
     */
    public static function validateRequest(Request $request, array $rules, array $messages = [], bool $required = true, ?User $existing = null): array
    {
        $values = null;
        $checkUnits = $existing === null || $request->has('college');
        $validator = Validator::make($request->all(), $rules, $messages);
        $validator->after(function ($validator) use ($request, $required, $existing, $checkUnits, &$values) {
            if (! $checkUnits) {
                return;
            }
            [$errors, $values] = self::check($request->all(), $required, $existing);
            foreach ($errors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
        $data = $validator->validate();
        unset($data['college'], $data['department'], $data['office_name']);

        return $checkUnits ? $data + $values : $data;
    }

    /**
     * The college / department rule on its own: [errors, values to store].
     *
     * - The college must be in the list; the department must belong to it
     *   (units without departments store none); "Other office / unit" needs
     *   the office or unit name (max 150), stored as the department.
     * - $existing (Edit details): a saved pair that is not in the list is
     *   kept while BOTH values are unchanged; a changed one must match.
     * - $required false: an empty college is allowed (nothing stored).
     *
     * @return array{0: array<string, string>, 1: array{college: ?string, department: ?string}}
     */
    public static function check(array $input, bool $required = true, ?User $existing = null): array
    {
        $college = self::clean($input['college'] ?? null);
        $department = self::clean($input['department'] ?? null);
        $office = self::clean($input['office_name'] ?? null);
        $other = $college === self::otherName();
        if ($other && $office === null) {
            $office = $department; // an older client may send the office name as department
        }
        $chosen = ['college' => $college, 'department' => $other ? $office : $department];

        if ($existing !== null
            && $chosen['college'] === self::clean($existing->college)
            && $chosen['department'] === self::clean($existing->department)) {
            return [[], $chosen];
        }

        if ($college === null) {
            return [$required ? ['college' => 'Select your college or unit.'] : [], ['college' => null, 'department' => null]];
        }

        $unit = self::find($college);
        if ($unit === null) {
            return [['college' => 'Select a college or unit from the list.'], $chosen];
        }
        if ($unit['other']) {
            if ($office === null) {
                return [['office_name' => 'Enter the office or unit name.'], $chosen];
            }
            if (mb_strlen($office) > self::officeMax()) {
                return [['office_name' => 'The office or unit name may not be longer than ' . self::officeMax() . ' characters.'], $chosen];
            }

            return [[], $chosen];
        }
        if ($unit['departments'] === []) {
            return [[], ['college' => $college, 'department' => null]];
        }
        if ($department === null) {
            return [['department' => 'Select your department.'], $chosen];
        }
        if (! in_array($department, $unit['departments'], true)) {
            return [['department' => "Select a department of the {$college}."], $chosen];
        }

        return [[], $chosen];
    }

    /** Users whose saved college / department is not in the list (kept as they are). */
    public static function legacyUsers()
    {
        return User::query()->get(['id', 'email', 'college', 'department'])->filter(function (User $user) {
            $college = self::clean($user->college);
            if ($college === null) {
                return self::clean($user->department) !== null;
            }
            $unit = self::find($college);
            if ($unit === null) {
                return true;
            }
            if ($unit['other']) {
                return self::clean($user->department) === null;
            }

            return $unit['departments'] === []
                ? self::clean($user->department) !== null
                : ! in_array(self::clean($user->department), $unit['departments'], true);
        })->values();
    }

    private static function clean($value): ?string
    {
        $text = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value)) : null;

        return $text === '' ? null : $text;
    }
}
