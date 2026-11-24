export type PlatformKey = 'google_ads' | 'meta' | 'ga4' | 'tiktok';

export interface PlatformInfo {
  key: PlatformKey;
  label: string;
  api: string;
  initial: string;
  color: string;
  bg: string;
  settingsRoute: string;
  comingSoon?: boolean;
}

export const PLATFORMS: PlatformInfo[] = [
  { key: 'google_ads', label: 'Google Ads', api: 'Conversion uploads', initial: 'G', color: '#1a73e8', bg: '#e8f0fe', settingsRoute: '/settings/google-ads' },
  { key: 'meta', label: 'Meta', api: 'Conversions API', initial: 'M', color: '#0866ff', bg: '#e7f0ff', settingsRoute: '/settings/meta' },
  { key: 'ga4', label: 'Google Analytics 4', api: 'Measurement Protocol', initial: 'A', color: '#c25e00', bg: '#fff1e0', settingsRoute: '/settings/ga4' },
  { key: 'tiktok', label: 'TikTok', api: 'Events API', initial: 'T', color: '#ffffff', bg: '#1f1f1f', settingsRoute: '/settings/tiktok', comingSoon: true },
];

export function platformInfo(key: string | undefined): PlatformInfo | undefined {
  return PLATFORMS.find((p) => p.key === key);
}

export function platformLabel(key: string): string {
  return platformInfo(key)?.label ?? key;
}
