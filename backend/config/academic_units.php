<?php

/*
|--------------------------------------------------------------------------
| MSU-Main academic units (the ONE list)
|--------------------------------------------------------------------------
|
| Colleges / units and their departments, exactly as named in the
| msumain.edu.ph university overview. The frontend reads this list from
| GET /api/academic-units (App\Support\AcademicUnits); it is never typed a
| second time. Every unit with departments also gets "Graduate Studies"
| (added by AcademicUnits, not repeated here). Units with no departments
| hide the Department field. "Other office / unit" asks for the office or
| unit name instead (stored in users.department).
|
| Changing a name here does not rewrite existing users: their saved value
| is kept and shown as it is until someone changes it.
|
*/

return [
    'graduate_studies' => 'Graduate Studies',

    'other' => [
        'name' => 'Other office / unit',
        'office_label' => 'Office or unit name',
        'office_max' => 150,
    ],

    'units' => [
        'College of Agriculture' => ['Agribusiness Management', 'Agricultural Education and Extension', 'Animal Science', 'Plant Science'],
        'College of Business Administration and Accountancy' => ['Accountancy', 'Economics', 'Management', 'Marketing'],
        'College of Education' => ['Elementary Teaching', 'Home Economics', 'Secondary Teaching'],
        'College of Engineering' => ['Agricultural Engineering', 'Chemical Engineering', 'Civil Engineering', 'Electrical and Electronic Engineering', 'Mechanical Engineering'],
        'Division of Engineering Technology' => ['Metal', 'Wood'],
        'College of Fisheries and Aquatic Sciences' => ['Fisheries', 'Fisheries Technology'],
        'College of Forestry and Environmental Studies' => ['Environmental Studies', 'Forestry'],
        'College of Health Sciences' => ['Nursing'],
        'College of Hospitality and Tourism Management' => ['Hospitality Management', 'Tourism Management'],
        'College of Information and Computing Sciences' => ['Computing Sciences', 'Information Sciences'],
        'Institute of Science Education' => [],
        'King Faisal Center for Islamic, Arabic and Asian Studies' => ['International Relations', 'Islamic Studies', 'Teaching Arabic'],
        'College of Law' => [],
        'College of Medicine' => [],
        'College of Natural Sciences and Mathematics' => ['Biology', 'Chemistry', 'Mathematics', 'Physics'],
        'College of Public Affairs' => ['Public Administration', 'Social Work', 'Sustainable Community Development'],
        'College of Social Sciences and Humanities' => ['Communication and Media Studies', 'English', 'Filipino', 'History', 'Library and Information Science', 'Philosophy', 'Political Science', 'Psychology', 'Sociology'],
        'College of Sports, Physical Education and Recreation' => ['Physical Education'],
    ],
];
