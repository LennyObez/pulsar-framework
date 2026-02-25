/**
 * Command-pattern undo/redo stack for the page builder.
 *
 * Each executed command is pushed to the undo stack. Undo pops the command
 * and invokes its `undo()` method. Redo replays undone commands. Executing
 * a new command clears the redo stack.
 */

export interface Command {
  execute(): void;
  undo(): void;
  description: string;
}

export class UndoStack {
  private undoStack: Command[] = [];
  private redoStack: Command[] = [];
  private readonly maxSize: number;

  onChange?: () => void;

  constructor(maxSize: number = 50) {
    this.maxSize = maxSize;
  }

  execute(command: Command): void {
    command.execute();
    this.undoStack.push(command);
    this.redoStack = [];

    if (this.undoStack.length > this.maxSize) {
      this.undoStack.shift();
    }

    this.onChange?.();
  }

  undo(): void {
    const command = this.undoStack.pop();
    if (!command) {
      return;
    }

    command.undo();
    this.redoStack.push(command);
    this.onChange?.();
  }

  redo(): void {
    const command = this.redoStack.pop();
    if (!command) {
      return;
    }

    command.execute();
    this.undoStack.push(command);
    this.onChange?.();
  }

  canUndo(): boolean {
    return this.undoStack.length > 0;
  }

  canRedo(): boolean {
    return this.redoStack.length > 0;
  }

  clear(): void {
    this.undoStack = [];
    this.redoStack = [];
    this.onChange?.();
  }
}
