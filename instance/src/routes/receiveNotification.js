'use strict';

// Push-only режим — pull всегда возвращает null.
module.exports = () => (_req, res) => {
  res.json(null);
};
