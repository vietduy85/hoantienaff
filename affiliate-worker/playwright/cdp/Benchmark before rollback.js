class Benchmark {
  constructor() {
    this._marks = {};
    this._order = [];
  }

  start(name) {
    this._marks[name] = { start: Date.now(), end: null };
    if (!this._order.includes(name)) this._order.push(name);
  }

  end(name) {
    const m = this._marks[name];
    if (!m) return 0;
    m.end = Date.now();
    return m.end - m.start;
  }

  mark(name) {
    this._marks[name] = { start: Date.now(), end: null };
    if (!this._order.includes(name)) this._order.push(name);
  }

  elapsed(name) {
    const m = this._marks[name];
    if (!m) return 0;
    if (m.end) return m.end - m.start;
    return Date.now() - m.start;
  }

  report() {
    const r = {};
    for (const name of this._order) {
      r[name] = this.elapsed(name);
    }
    return r;
  }

  toJSON() {
    return this.report();
  }
}

module.exports = Benchmark;
