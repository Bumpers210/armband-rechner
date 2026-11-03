'use strict';

// Older ESLint plugins call require('minimatch') directly. v11 exports a
// namespace instead. Keep that call shape, but delegate ALL matching to v11.
// eslint-disable-next-line @typescript-eslint/no-require-imports
const engine = require('minimatch-v11');

function compatible(match) {
  const facade = (path, pattern, options) => match(path, pattern, options);
  Object.assign(facade, match);
  facade.minimatch = facade;
  facade.defaults = options => compatible(match.defaults(options));

  // eslint-plugin-import/no-internal-modules only needs makeRe(...).test().
  // Do not recreate a backtracking RegExp (removed by v11 for security).
  // This is deliberately NOT a general RegExp compatibility implementation.
  facade.makeRe = (pattern, options) => {
    const matcher = new match.Minimatch(pattern, options);
    return Object.freeze({ test: path => matcher.match(path) });
  };
  return facade;
}

module.exports = compatible(engine.minimatch);
