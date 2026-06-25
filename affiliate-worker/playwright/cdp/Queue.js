class Queue {
  constructor() {
    this._running = false;
    this._pending = [];
  }

  async enqueue(fn) {
    return new Promise((resolve, reject) => {
      this._pending.push({ fn, resolve, reject });
      this._process();
    });
  }

  async _process() {
    if (this._running) return;
    this._running = true;

    while (this._pending.length > 0) {
      const { fn, resolve, reject } = this._pending.shift();
      try {
        const result = await fn();
        resolve(result);
      } catch (err) {
        reject(err);
      }
    }

    this._running = false;
  }
}

module.exports = new Queue();
