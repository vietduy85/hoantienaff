const fs = require('fs');
const path = require('path');

const LEVELS = { DEBUG: 0, INFO: 1, WARN: 2, ERROR: 3 };

class Logger {
  constructor(debugDir, level) {
    this._debugDir = debugDir;
    this._level = level || 'INFO';
    this._buffer = [];
  }

  _timestamp() {
    const d = new Date();
    const pad = n => String(n).padStart(2, '0');
    return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  }

  _write(level, prefix, message) {
    if (LEVELS[level] < LEVELS[this._level]) return;
    const line = `[${this._timestamp()}] [${level}] [${prefix}] ${message}`;
    console.log(line);
    this._buffer.push(line);
  }

  debug(prefix, message) { this._write('DEBUG', prefix, message); }
  info(prefix, message) { this._write('INFO', prefix, message); }
  warn(prefix, message) { this._write('WARN', prefix, message); }
  error(prefix, message) { this._write('ERROR', prefix, message); }

  log(prefix, message) { this.info(prefix, message); }

  save() {
    if (!this._debugDir) return;
    try {
      fs.writeFileSync(
        path.join(this._debugDir, 'log.txt'),
        this._buffer.join('\n'),
        'utf-8'
      );
    } catch {}
  }

  flush() {
    this._buffer = [];
  }
}

module.exports = Logger;
