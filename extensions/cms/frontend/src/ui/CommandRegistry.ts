/**
 * Registry of commands available to the command palette.
 *
 * Supports registration of arbitrary commands with scored substring and
 * fuzzy subsequence matching. Built-in CMS navigation and action commands
 * are registered on init.
 */

interface Command {
  readonly id: string;
  readonly label: string;
  readonly category: string;
  readonly icon?: string;
  readonly keywords: string[];
  readonly action: () => void;
}

class CommandRegistryImpl {
  private readonly commands = new Map<string, Command>();

  /** Register a command. Overwrites if the ID already exists. */
  register(command: Command): void {
    this.commands.set(command.id, command);
  }

  /** Unregister a command by ID. */
  unregister(id: string): void {
    this.commands.delete(id);
  }

  /** Return all registered commands. */
  all(): Command[] {
    return [...this.commands.values()];
  }

  /**
   * Search commands by query string.
   *
   * Uses case-insensitive matching against label and keywords.
   * Scores results by match quality: label prefix > label substring >
   * fuzzy subsequence > keyword match.
   */
  search(query: string): Command[] {
    if (query.trim() === '') {
      return this.all();
    }

    const q = query.toLowerCase();
    const scored: Array<{ command: Command; score: number }> = [];

    for (const command of this.commands.values()) {
      const score = this.computeScore(command, q);
      if (score > 0) {
        scored.push({ command, score });
      }
    }

    scored.sort((a, b) => b.score - a.score);
    return scored.slice(0, 10).map((s) => s.command);
  }

  private computeScore(command: Command, query: string): number {
    const label = command.label.toLowerCase();
    let score = 0;

    // Exact prefix match on label is highest
    if (label.startsWith(query)) {
      score += 100;
    } else if (label.includes(query)) {
      score += 50;
    }

    // Word boundary match
    const words = label.split(/\s+/);
    for (const word of words) {
      if (word.startsWith(query)) {
        score += 30;
      }
    }

    // Fuzzy subsequence match: all query chars appear in order in the label
    if (score === 0) {
      score += this.fuzzySubsequenceScore(label, query);
    }

    // Keyword matching
    for (const kw of command.keywords) {
      const kwLower = kw.toLowerCase();
      if (kwLower.startsWith(query)) {
        score += 20;
      } else if (kwLower.includes(query)) {
        score += 10;
      }
    }

    // Category match
    if (command.category.toLowerCase().includes(query)) {
      score += 5;
    }

    return score;
  }

  /**
   * Score a fuzzy subsequence match: all query characters must appear in
   * order within the text. Consecutive matches score higher than gaps.
   */
  private fuzzySubsequenceScore(text: string, query: string): number {
    let textIdx = 0;
    let score = 0;

    for (const char of query) {
      const found = text.indexOf(char, textIdx);
      if (found === -1) {
        return 0;
      }
      // Consecutive character match scores higher than a gap
      score += found === textIdx ? 2 : 1;
      textIdx = found + 1;
    }

    return score;
  }
}

/** Singleton command registry. */
const CommandRegistry = new CommandRegistryImpl();

// Register built-in CMS navigation commands
const NAV_COMMANDS: ReadonlyArray<Omit<Command, 'action'> & { href: string }> = [
  {
    id: 'nav:dashboard',
    label: 'Dashboard',
    category: 'Navigate',
    icon: 'fa-solid fa-gauge',
    keywords: ['home', 'overview', 'main'],
    href: '/admin/cms',
  },
  {
    id: 'nav:content',
    label: 'Content',
    category: 'Navigate',
    icon: 'fa-solid fa-file-lines',
    keywords: ['articles', 'pages', 'posts', 'entries'],
    href: '/admin/cms/content',
  },
  {
    id: 'nav:media',
    label: 'Media Library',
    category: 'Navigate',
    icon: 'fa-solid fa-images',
    keywords: ['images', 'files', 'uploads', 'assets'],
    href: '/admin/cms/media',
  },
  {
    id: 'nav:taxonomies',
    label: 'Taxonomies',
    category: 'Navigate',
    icon: 'fa-solid fa-tags',
    keywords: ['categories', 'tags', 'terms'],
    href: '/admin/cms/taxonomy',
  },
  {
    id: 'nav:menus',
    label: 'Menus',
    category: 'Navigate',
    icon: 'fa-solid fa-bars',
    keywords: ['navigation', 'links', 'menu builder'],
    href: '/admin/cms/menus',
  },
  {
    id: 'nav:settings',
    label: 'Settings',
    category: 'Navigate',
    icon: 'fa-solid fa-gear',
    keywords: ['configuration', 'options', 'preferences'],
    href: '/admin/cms/settings',
  },
  {
    id: 'nav:users',
    label: 'Users',
    category: 'Navigate',
    icon: 'fa-solid fa-users',
    keywords: ['accounts', 'members', 'roles'],
    href: '/admin/cms/users',
  },
  {
    id: 'nav:comments',
    label: 'Comments',
    category: 'Navigate',
    icon: 'fa-solid fa-comments',
    keywords: ['moderation', 'replies', 'discussion'],
    href: '/admin/cms/comments',
  },
];

for (const nav of NAV_COMMANDS) {
  CommandRegistry.register({
    id: nav.id,
    label: nav.label,
    category: nav.category,
    ...(nav.icon !== undefined ? { icon: nav.icon } : {}),
    keywords: nav.keywords,
    action: () => {
      window.location.href = nav.href;
    },
  });
}

// Register built-in CMS action commands
const ACTION_COMMANDS: ReadonlyArray<Omit<Command, 'action'> & { href: string }> = [
  {
    id: 'action:new-content',
    label: 'Create New Content',
    category: 'Actions',
    icon: 'fa-solid fa-plus',
    keywords: ['new', 'article', 'page', 'create', 'add'],
    href: '/admin/cms/content/create',
  },
  {
    id: 'action:upload-media',
    label: 'Upload Media',
    category: 'Actions',
    icon: 'fa-solid fa-upload',
    keywords: ['upload', 'image', 'file', 'import'],
    href: '/admin/cms/media?upload=1',
  },
  {
    id: 'action:clear-cache',
    label: 'Clear Cache',
    category: 'Actions',
    icon: 'fa-solid fa-broom',
    keywords: ['purge', 'refresh', 'flush', 'cache'],
    href: '/admin/cms/settings?action=clear-cache',
  },
];

for (const action of ACTION_COMMANDS) {
  CommandRegistry.register({
    id: action.id,
    label: action.label,
    category: action.category,
    ...(action.icon !== undefined ? { icon: action.icon } : {}),
    keywords: action.keywords,
    action: () => {
      window.location.href = action.href;
    },
  });
}

// Register built-in CMS tool commands
const TOOL_COMMANDS: ReadonlyArray<Omit<Command, 'action'> & { href: string }> = [
  {
    id: 'tool:export',
    label: 'Export Content',
    category: 'Tools',
    icon: 'fa-solid fa-download',
    keywords: ['export', 'download', 'backup', 'json'],
    href: '/admin/cms/tools/export',
  },
  {
    id: 'tool:import',
    label: 'Import Content',
    category: 'Tools',
    icon: 'fa-solid fa-file-import',
    keywords: ['import', 'upload', 'restore', 'migrate'],
    href: '/admin/cms/tools/import',
  },
  {
    id: 'tool:backup',
    label: 'Backup Site',
    category: 'Tools',
    icon: 'fa-solid fa-shield',
    keywords: ['backup', 'snapshot', 'safety', 'archive'],
    href: '/admin/cms/tools/backups',
  },
];

for (const tool of TOOL_COMMANDS) {
  CommandRegistry.register({
    id: tool.id,
    label: tool.label,
    category: tool.category,
    ...(tool.icon !== undefined ? { icon: tool.icon } : {}),
    keywords: tool.keywords,
    action: () => {
      window.location.href = tool.href;
    },
  });
}

export { CommandRegistry };
export type { Command };
