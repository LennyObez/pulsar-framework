import type { DeploymentViewerData, DeploymentEntry, DeploymentCommit } from '../types.js';

/**
 * Render the deployment diff viewer using safe DOM APIs (no innerHTML).
 */
export function renderDeploymentViewer(container: HTMLElement, payload: unknown): void {
  const data = payload as DeploymentViewerData | null;

  while (container.firstChild) {
    container.removeChild(container.firstChild);
  }

  container.appendChild(buildNav('deployments'));

  const dashboard = document.createElement('div');
  dashboard.className = 'dashboard';

  if (!data || data.deployments.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'empty-state';
    const h2 = document.createElement('h2');
    h2.textContent = 'No deployments found';
    const p = document.createElement('p');
    p.textContent =
      'No version tags found in the repository. Create tags (e.g., v1.0.0) to track deployments.';
    empty.appendChild(h2);
    empty.appendChild(p);
    const link = document.createElement('a');
    link.href = '/studio';
    link.className = 'btn';
    link.textContent = 'Back to Studio';
    empty.appendChild(link);
    dashboard.appendChild(empty);
    container.appendChild(dashboard);
    return;
  }

  // Header
  const header = document.createElement('div');
  header.className = 'dashboard-header';
  const h1 = document.createElement('h1');
  h1.textContent = 'Deployments';
  const subtitle = document.createElement('p');
  subtitle.textContent =
    'Current: ' + data.current_ref + ' | ' + String(data.tag_count) + ' version tags';
  header.appendChild(h1);
  header.appendChild(subtitle);
  dashboard.appendChild(header);

  // Deployment entries
  for (const deployment of data.deployments) {
    dashboard.appendChild(buildDeploymentCard(deployment));
  }

  container.appendChild(dashboard);
}

function buildNav(activePage: string): HTMLElement {
  const nav = document.createElement('nav');
  nav.className = 'studio-nav';

  const brand = document.createElement('a');
  brand.href = '/studio';
  brand.className = 'nav-brand';
  brand.textContent = 'Pulsar Studio';
  nav.appendChild(brand);

  const links = document.createElement('div');
  links.className = 'nav-links';

  const navItems = [
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

  for (const item of navItems) {
    const a = document.createElement('a');
    a.href = item.href;
    a.textContent = item.label;
    if (item.id === activePage) {
      a.className = 'active';
    }
    links.appendChild(a);
  }

  nav.appendChild(links);
  return nav;
}

function buildDeploymentCard(deployment: DeploymentEntry): HTMLElement {
  const card = document.createElement('div');
  card.className = 'card';

  // Title row
  const titleRow = document.createElement('div');
  titleRow.style.cssText =
    'display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4);padding-bottom:var(--space-2);border-bottom:1px solid var(--color-border);';

  const tagEl = document.createElement('h3');
  tagEl.style.cssText = 'margin:0;padding:0;border:0;';
  tagEl.textContent = deployment.tag;
  titleRow.appendChild(tagEl);

  if (deployment.stats) {
    const statsSpan = document.createElement('span');
    statsSpan.style.cssText =
      'font-size:var(--text-xs);color:var(--color-text-muted);display:flex;gap:1rem;';

    const filesEl = document.createElement('span');
    filesEl.textContent = String(deployment.stats.files_changed) + ' files';
    statsSpan.appendChild(filesEl);

    const addEl = document.createElement('span');
    addEl.style.color = 'var(--color-success-400)';
    addEl.textContent = '+' + String(deployment.stats.insertions);
    statsSpan.appendChild(addEl);

    const delEl = document.createElement('span');
    delEl.style.color = 'var(--color-danger-400)';
    delEl.textContent = '-' + String(deployment.stats.deletions);
    statsSpan.appendChild(delEl);

    titleRow.appendChild(statsSpan);
  }

  card.appendChild(titleRow);

  // Range label
  if (deployment.previous_tag) {
    const range = document.createElement('div');
    range.style.cssText =
      'font-size:var(--text-xs);color:var(--color-text-disabled);margin-bottom:var(--space-4);';
    range.textContent = deployment.previous_tag + ' ... ' + deployment.tag;
    card.appendChild(range);
  }

  // Commits table
  if (deployment.commits.length > 0) {
    card.appendChild(buildCommitTable(deployment.commits));
  } else {
    const noCommits = document.createElement('p');
    noCommits.style.cssText = 'color:var(--color-text-disabled);font-size:var(--text-sm);';
    noCommits.textContent = 'No commits in this range.';
    card.appendChild(noCommits);
  }

  return card;
}

function buildCommitTable(commits: DeploymentCommit[]): HTMLTableElement {
  const table = document.createElement('table');
  table.className = 'data-table';

  const thead = document.createElement('thead');
  const headerRow = document.createElement('tr');
  for (const label of ['Hash', 'Message', 'Author', 'Date']) {
    const th = document.createElement('th');
    th.textContent = label;
    headerRow.appendChild(th);
  }
  thead.appendChild(headerRow);
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  for (const commit of commits) {
    const tr = document.createElement('tr');

    const hashTd = document.createElement('td');
    const hashCode = document.createElement('code');
    hashCode.textContent = commit.short_hash;
    hashCode.title = commit.hash;
    hashTd.appendChild(hashCode);
    tr.appendChild(hashTd);

    const msgTd = document.createElement('td');
    msgTd.textContent = commit.subject;
    msgTd.style.maxWidth = '400px';
    tr.appendChild(msgTd);

    const authorTd = document.createElement('td');
    authorTd.textContent = commit.author;
    authorTd.style.cssText = 'white-space:nowrap;color:var(--color-text-muted);';
    tr.appendChild(authorTd);

    const dateTd = document.createElement('td');
    dateTd.className = 'time-cell';
    dateTd.textContent = commit.date;
    tr.appendChild(dateTd);

    tbody.appendChild(tr);
  }

  table.appendChild(tbody);
  return table;
}
