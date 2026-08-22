import { describe, it, expect, vi } from 'vitest';
import { UndoStack, type Command } from '../UndoStack.js';

function makeCommand(desc = 'test'): Command & { executeCalls: number; undoCalls: number } {
  const cmd = {
    description: desc,
    executeCalls: 0,
    undoCalls: 0,
    execute() {
      cmd.executeCalls++;
    },
    undo() {
      cmd.undoCalls++;
    },
  };
  return cmd;
}

describe('UndoStack', () => {
  it('executes a command and reports canUndo', () => {
    const stack = new UndoStack();
    expect(stack.canUndo()).toBe(false);

    const cmd = makeCommand();
    stack.execute(cmd);
    expect(cmd.executeCalls).toBe(1);
    expect(stack.canUndo()).toBe(true);
    expect(stack.canRedo()).toBe(false);
  });

  it('undoes a command', () => {
    const stack = new UndoStack();
    const cmd = makeCommand();
    stack.execute(cmd);

    stack.undo();
    expect(cmd.undoCalls).toBe(1);
    expect(stack.canUndo()).toBe(false);
    expect(stack.canRedo()).toBe(true);
  });

  it('redoes a command', () => {
    const stack = new UndoStack();
    const cmd = makeCommand();
    stack.execute(cmd);
    stack.undo();

    stack.redo();
    expect(cmd.executeCalls).toBe(2);
    expect(stack.canUndo()).toBe(true);
    expect(stack.canRedo()).toBe(false);
  });

  it('clears redo stack on new execute', () => {
    const stack = new UndoStack();
    const cmd1 = makeCommand('first');
    const cmd2 = makeCommand('second');

    stack.execute(cmd1);
    stack.undo();
    expect(stack.canRedo()).toBe(true);

    stack.execute(cmd2);
    expect(stack.canRedo()).toBe(false);
  });

  it('respects max stack size', () => {
    const stack = new UndoStack(3);
    const cmds = Array.from({ length: 5 }, (_, i) => makeCommand(`cmd-${i}`));

    for (const cmd of cmds) {
      stack.execute(cmd);
    }

    // Should only be able to undo 3 times
    let undoCount = 0;
    while (stack.canUndo()) {
      stack.undo();
      undoCount++;
    }
    expect(undoCount).toBe(3);
  });

  it('invokes onChange callback', () => {
    const stack = new UndoStack();
    const onChange = vi.fn();
    stack.onChange = onChange;

    const cmd = makeCommand();
    stack.execute(cmd);
    expect(onChange).toHaveBeenCalledTimes(1);

    stack.undo();
    expect(onChange).toHaveBeenCalledTimes(2);

    stack.redo();
    expect(onChange).toHaveBeenCalledTimes(3);
  });

  it('clear resets both stacks', () => {
    const stack = new UndoStack();
    stack.execute(makeCommand());
    stack.execute(makeCommand());
    stack.undo();

    expect(stack.canUndo()).toBe(true);
    expect(stack.canRedo()).toBe(true);

    stack.clear();
    expect(stack.canUndo()).toBe(false);
    expect(stack.canRedo()).toBe(false);
  });

  it('undo on empty stack is a no-op', () => {
    const stack = new UndoStack();
    stack.undo(); // should not throw
    expect(stack.canUndo()).toBe(false);
  });

  it('redo on empty stack is a no-op', () => {
    const stack = new UndoStack();
    stack.redo(); // should not throw
    expect(stack.canRedo()).toBe(false);
  });
});
