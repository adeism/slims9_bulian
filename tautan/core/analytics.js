// Analytics - event queue non-blocking, daily aggregation, funnel
import { storage, generateId, nowISO } from './storage.js';
import { featureFlags } from './config.js';

export function trackEvent({ workspace_id, project_id = null, resource_type, resource_id, event_type, metadata = {} }) {
  if (!featureFlags.killSwitches.analyticsCollection) return;
  // non-blocking via setTimeout simulation of queue
  setTimeout(() => {
    const events = storage.getAnalyticsEvents();
    events.unshift({
      id: generateId(),
      workspace_id,
      project_id,
      resource_type,
      resource_id,
      event_type, // click, view, scan, form_start, form_completion, button_click, scroll, video_play
      metadata: {
        ...metadata,
        ip_hash: 'hashed_' + Math.random().toString(36).slice(2,8), // privacy
        device: metadata.device || detectDevice(),
        os: metadata.os || detectOS(),
        browser: metadata.browser || detectBrowser(),
        referer: metadata.referer || document.referrer || null,
        utm_source: metadata.utm_source || getUTM('utm_source'),
        utm_medium: metadata.utm_medium || getUTM('utm_medium'),
        utm_campaign: metadata.utm_campaign || getUTM('utm_campaign'),
        timestamp: nowISO()
      },
      created_at: nowISO()
    });
    if (events.length > 5000) events.length = 5000;
    storage.setAnalyticsEvents(events);
  }, 0);
}

function detectDevice() {
  const ua = navigator.userAgent;
  if (/Mobi|Android/i.test(ua)) return 'mobile';
  if (/Tablet|iPad/i.test(ua)) return 'tablet';
  return 'desktop';
}
function detectOS() {
  const ua = navigator.userAgent;
  if (/Windows/i.test(ua)) return 'Windows';
  if (/Mac/i.test(ua)) return 'macOS';
  if (/Linux/i.test(ua)) return 'Linux';
  if (/Android/i.test(ua)) return 'Android';
  if (/iOS|iPhone|iPad/i.test(ua)) return 'iOS';
  return 'Unknown';
}
function detectBrowser() {
  const ua = navigator.userAgent;
  if (/Chrome/i.test(ua)) return 'Chrome';
  if (/Firefox/i.test(ua)) return 'Firefox';
  if (/Safari/i.test(ua) && !/Chrome/i.test(ua)) return 'Safari';
  if (/Edge/i.test(ua)) return 'Edge';
  return 'Other';
}
function getUTM(key) {
  try {
    const params = new URLSearchParams(window.location.search);
    return params.get(key);
  } catch { return null; }
}

export function getDailyAggregation({ workspace_id, project_id = null, resource_type = null, resource_id = null, days = 30 }) {
  const events = storage.getAnalyticsEvents().filter(e => {
    if (workspace_id && e.workspace_id !== workspace_id) return false;
    if (project_id && e.project_id !== project_id) return false;
    if (resource_type && e.resource_type !== resource_type) return false;
    if (resource_id && e.resource_id !== resource_id) return false;
    return true;
  });
  // group by date
  const map = {};
  events.forEach(ev => {
    const date = ev.created_at.slice(0,10);
    if (!map[date]) map[date] = { date, views:0, clicks:0, scans:0, form_starts:0, form_completions:0, button_clicks:0 };
    if (['view','microsite_view'].includes(ev.event_type)) map[date].views++;
    if (['click','shortlink_click'].includes(ev.event_type)) map[date].clicks++;
    if (['scan','qr_scan'].includes(ev.event_type)) map[date].scans++;
    if (['form_start'].includes(ev.event_type)) map[date].form_starts++;
    if (['form_completion'].includes(ev.event_type)) map[date].form_completions++;
    if (['button_click'].includes(ev.event_type)) map[date].button_clicks++;
  });
  const sorted = Object.values(map).sort((a,b)=> a.date.localeCompare(b.date)).slice(-days);
  return sorted;
}

export function getFunnel({ workspace_id, project_id }) {
  const events = storage.getAnalyticsEvents().filter(e => {
    if (workspace_id && e.workspace_id !== workspace_id) return false;
    if (project_id && e.project_id !== project_id) return false;
    return true;
  });
  const reach = events.filter(e => ['scan','qr_scan','click','shortlink_click'].includes(e.event_type)).length;
  const visit = events.filter(e => ['view','microsite_view'].includes(e.event_type)).length;
  const engagement = events.filter(e => ['button_click','scroll','video_play'].includes(e.event_type)).length;
  const conversion = events.filter(e => ['form_completion'].includes(e.event_type)).length;
  return { reach, visit, engagement, conversion, completion_rate: visit ? Math.round(conversion/visit*100) : 0 };
}

export function getMetricsSummary({ workspace_id, project_id = null }) {
  const events = storage.getAnalyticsEvents().filter(e => {
    if (workspace_id && e.workspace_id !== workspace_id) return false;
    if (project_id && e.project_id !== project_id) return false;
    return true;
  });
  const uniqueVisitors = new Set(events.map(e => e.metadata.ip_hash)).size;
  const deviceBreakdown = {};
  const browserBreakdown = {};
  const osBreakdown = {};
  events.forEach(e => {
    deviceBreakdown[e.metadata.device] = (deviceBreakdown[e.metadata.device]||0)+1;
    browserBreakdown[e.metadata.browser] = (browserBreakdown[e.metadata.browser]||0)+1;
    osBreakdown[e.metadata.os] = (osBreakdown[e.metadata.os]||0)+1;
  });
  return {
    total_events: events.length,
    unique_visitors: uniqueVisitors,
    device: deviceBreakdown,
    browser: browserBreakdown,
    os: osBreakdown,
    funnel: getFunnel({ workspace_id, project_id }),
    daily: getDailyAggregation({ workspace_id, project_id })
  };
}
