const TIMING_ENABLED = process.env.AFFILIATE_TIMING === 'true';

let _counter = 0;

class Timer {
  constructor() {
    if (!TIMING_ENABLED) {
      this._enabled = false;
      return;
    }
    this._enabled = true;
    _counter++;
    this.requestId = `REQ-${String(_counter).padStart(4, '0')}`;
    this._marks = [];
    this._startTimes = {};
    this._startTime = Date.now();
    this._errored = false;
  }

  start(name) {
    if (!this._enabled) return;
    this._startTimes[name] = Date.now();
  }

  end(name) {
    if (!this._enabled) return;
    const start = this._startTimes[name];
    if (start === undefined) return;
    const elapsed = Date.now() - start;
    this._marks.push({ name, elapsed });
    console.log(`[${this.requestId}] [Timing]\n[${this.requestId}] ${name}\n[${this.requestId}] ${elapsed} ms\n`);
    delete this._startTimes[name];
  }

  log(name, elapsed) {
    if (!this._enabled) return;
    this._marks.push({ name, elapsed });
    console.log(`[${this.requestId}] [Timing]\n[${this.requestId}] ${name}\n[${this.requestId}] ${elapsed} ms\n`);
  }

  markError() {
    this._errored = true;
  }

  printSummary() {
    if (!this._enabled) return;
    const lines = [];
    const now = Date.now();
    lines.push(`[${this.requestId}] ========== Timing Summary ==========`);
    for (const m of this._marks) {
      lines.push(`[${this.requestId}] ${m.name} : ${m.elapsed} ms`);
    }
    const total = now - this._startTime;
    lines.push(`[${this.requestId}] ---`);
    lines.push(`[${this.requestId}] TOTAL : ${total} ms`);
    lines.push(`[${this.requestId}] ====================================`);
    console.log('\n' + lines.join('\n') + '\n');
  }
}

module.exports = Timer;
