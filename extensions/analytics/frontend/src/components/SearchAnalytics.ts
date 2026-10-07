import type { SearchOverview, SearchQueryEntry } from '../types';

/**
 * Renders search analytics: overview metrics, top queries table,
 * and zero-result queries list.
 */
export function renderSearchAnalytics(
  container: HTMLElement,
  overview: SearchOverview,
  topQueries: SearchQueryEntry[],
  zeroResultQueries: SearchQueryEntry[],
): void {
  container.textContent = '';

  const wrapper = document.createElement('div');
  wrapper.className = 'search-analytics';

  // Overview cards
  const cards = document.createElement('div');
  cards.className = 'search-cards';

  const overviewMetrics = [
    { label: 'Total Searches', value: overview.total_searches.toLocaleString() },
    { label: 'Unique Queries', value: overview.unique_queries.toLocaleString() },
    { label: 'Zero Result Rate', value: `${overview.zero_result_rate.toFixed(1)}%` },
    { label: 'Avg CTR', value: `${overview.avg_click_through_rate.toFixed(1)}%` },
  ];

  for (const m of overviewMetrics) {
    const card = document.createElement('div');
    card.className = 'search-card';

    const valueEl = document.createElement('div');
    valueEl.className = 'search-card__value';
    valueEl.textContent = m.value;
    card.appendChild(valueEl);

    const labelEl = document.createElement('div');
    labelEl.className = 'search-card__label';
    labelEl.textContent = m.label;
    card.appendChild(labelEl);

    cards.appendChild(card);
  }

  wrapper.appendChild(cards);

  // Top queries
  if (topQueries.length > 0) {
    const topSection = document.createElement('div');
    topSection.className = 'search-top-queries';

    const topTitle = document.createElement('h3');
    topTitle.className = 'search-section__title';
    topTitle.textContent = 'Top Search Queries';
    topSection.appendChild(topTitle);

    const maxCount = Math.max(...topQueries.map((q) => q.count), 1);

    for (const query of topQueries) {
      const row = document.createElement('div');
      row.className = 'search-query-row';

      const nameEl = document.createElement('span');
      nameEl.className = 'search-query__text';
      nameEl.textContent = query.query;
      row.appendChild(nameEl);

      const barWrap = document.createElement('div');
      barWrap.className = 'search-query__bar-wrap';

      const bar = document.createElement('div');
      bar.className = 'search-query__bar';
      bar.style.width = `${((query.count / maxCount) * 100).toFixed(1)}%`;
      barWrap.appendChild(bar);

      row.appendChild(barWrap);

      const stats = document.createElement('span');
      stats.className = 'search-query__count';
      const parts = [query.count.toLocaleString()];
      if (query.click_through_rate !== undefined) {
        parts.push(`${query.click_through_rate.toFixed(1)}% CTR`);
      }
      stats.textContent = parts.join(' \u00B7 ');
      row.appendChild(stats);

      topSection.appendChild(row);
    }

    wrapper.appendChild(topSection);
  }

  // Zero-result queries
  if (zeroResultQueries.length > 0) {
    const zeroSection = document.createElement('div');
    zeroSection.className = 'search-zero-results';

    const zeroTitle = document.createElement('h3');
    zeroTitle.className = 'search-section__title';
    zeroTitle.textContent = 'Zero-Result Queries';
    zeroSection.appendChild(zeroTitle);

    const zeroDesc = document.createElement('p');
    zeroDesc.className = 'search-zero-results__desc';
    zeroDesc.textContent =
      'Queries that returned no results — consider adding content for these terms.';
    zeroSection.appendChild(zeroDesc);

    const list = document.createElement('div');
    list.className = 'search-zero-list';

    for (const query of zeroResultQueries) {
      const item = document.createElement('div');
      item.className = 'search-zero-list__item';

      const queryEl = document.createElement('span');
      queryEl.className = 'search-zero-list__query';
      queryEl.textContent = query.query;
      item.appendChild(queryEl);

      const countEl = document.createElement('span');
      countEl.className = 'search-zero-list__count';
      countEl.textContent = `${query.count.toLocaleString()} searches`;
      item.appendChild(countEl);

      list.appendChild(item);
    }

    zeroSection.appendChild(list);
    wrapper.appendChild(zeroSection);
  }

  container.appendChild(wrapper);
}
