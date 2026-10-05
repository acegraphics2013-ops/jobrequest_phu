export interface WhePermissions {
  ward_health_education: boolean;
  whe_overview: boolean;
  whe_new: boolean;
  whe_sessions: boolean;
  whe_visuals: boolean;
  whe_reports: boolean;
}

export const defaultPermissions: WhePermissions = {
  ward_health_education: true,
  whe_overview: true,
  whe_new: true,
  whe_sessions: true,
  whe_visuals: true,
  whe_reports: true,
};
