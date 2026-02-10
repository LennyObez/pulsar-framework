import { deleteGoal, fetchGoals } from '../api';

export async function renderGoalManager(container: HTMLElement, siteId: string): Promise<void> {
  const { data: goals } = await fetchGoals(siteId);

  if (goals.length === 0) {
    container.innerHTML =
      '<p class="goals-empty">No goals configured. Create one to start tracking conversions.</p>';
    return;
  }

  container.innerHTML = `
    <table class="goals-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Type</th>
          <th>Target</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        ${goals
          .map(
            (g) => `
          <tr data-goal-id="${g.id}">
            <td>${escapeHtml(g.name)}</td>
            <td>${g.goal_type === 'page_visit' ? 'Page Visit' : 'Custom Event'}</td>
            <td><code>${escapeHtml(g.target_value)}</code></td>
            <td>
              <button class="btn btn-sm goal-edit" data-id="${g.id}">Edit</button>
              <button class="btn btn-sm btn-danger goal-delete" data-id="${g.id}">Delete</button>
            </td>
          </tr>
        `,
          )
          .join('')}
      </tbody>
    </table>
  `;

  container.querySelectorAll<HTMLButtonElement>('.goal-delete').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.id!;
      if (confirm('Delete this goal?')) {
        await deleteGoal(id);
        await renderGoalManager(container, siteId);
      }
    });
  });
}

function escapeHtml(str: string): string {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}
