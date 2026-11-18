import { ENDPOINTS } from '../api/endpoints';
import { useApiData } from './useApiData';

/** The MSU-Main colleges / units list (GET /api/academic-units): { status, data, error, retry }. */
export function useAcademicUnits() {
  return useApiData(ENDPOINTS.academicUnits);
}
