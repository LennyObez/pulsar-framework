import { escapeHtml } from '../utils/escapeHtml.js';

interface NavLink {
  href: string;
  label: string;
  id: string;
}

const NAV_LINKS: NavLink[] = [
  { href: '/studio/console', label: 'Overview', id: 'overview' },
  { href: '/studio/console/requests', label: 'Requests', id: 'requests' },
  { href: '/studio/console/database', label: 'Database', id: 'database' },
  { href: '/studio/console/logs', label: 'Logs', id: 'logs' },
  { href: '/studio/console/exceptions', label: 'Exceptions', id: 'exceptions' },
  { href: '/studio/console/benchmarks', label: 'Benchmarks', id: 'benchmarks' },
  { href: '/studio/console/activity', label: 'Activity', id: 'activity' },
  { href: '/studio/console/health', label: 'Health', id: 'health' },
  { href: '/studio/console/deployments', label: 'Deploys', id: 'deployments' },
];

export function renderStudioNav(activePage: string): string {
  const links = NAV_LINKS.map(
    (link) =>
      `<a href="${escapeHtml(link.href)}"${link.id === activePage ? ' class="active"' : ''}>${escapeHtml(link.label)}</a>`,
  ).join('\n        ');

  return `
    <nav class="studio-nav">
      <a href="/studio" class="nav-brand">Pulsar Studio</a>
      <div class="nav-links">
        ${links}
      </div>
    </nav>
  `;
}
