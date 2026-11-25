const { defineConfig } = require('cypress');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');

const { TEST_DOMAIN } = require('./cypress/support/constants');

// Matches config/database.php (XAMPP defaults). Override with DB_HOST/DB_USER/DB_PASS/DB_NAME env vars.
function dbConfig() {
  const env = process.env;
  return {
    host: env.DB_HOST || 'localhost',
    user: env.DB_USER || 'root',
    password: env.DB_PASS || '',
    database: env.DB_NAME || 'afterbill',
    timezone: '+08:00',
  };
}

async function withDb(fn) {
  const conn = await mysql.createConnection(dbConfig());
  try {
    return await fn(conn);
  } finally {
    await conn.end();
  }
}

module.exports = defineConfig({
  e2e: {
    baseUrl: 'http://localhost/sqa-afterbill',
    viewportWidth: 1280,
    viewportHeight: 800,
    video: false,
    setupNodeEvents(on, config) {
      on('task', {
        /** Create (or replace) a user. verified=false leaves email_verified_at NULL. */
        async 'db:createUser'({ email, password, fullName = 'Cypress Tester', verified = true }) {
          if (!email.endsWith('@' + TEST_DOMAIN)) {
            throw new Error(`Test users must use @${TEST_DOMAIN}`);
          }
          const hash = await bcrypt.hash(password, 10); // $2b$, accepted by PHP password_verify()
          return withDb(async (db) => {
            await db.execute('DELETE FROM users WHERE email = ?', [email]);
            const [res] = await db.execute(
              'INSERT INTO users (full_name, email, password, email_verified_at, created_at) VALUES (?, ?, ?, ?, NOW())',
              [fullName, email, hash, verified ? new Date() : null]
            );
            return res.insertId;
          });
        },

        /** Look up a user row (or null). */
        'db:getUser'(email) {
          return withDb(async (db) => {
            const [rows] = await db.execute(
              'SELECT id, full_name, email, email_verified_at FROM users WHERE email = ?',
              [email]
            );
            return rows[0] || null;
          });
        },

        /** Delete every test user (bills, budgets, tokens, activity cascade) and rate-limit rows. */
        'db:reset'() {
          return withDb(async (db) => {
            await db.execute('DELETE FROM users WHERE email LIKE ?', ['%@' + TEST_DOMAIN]);
            await db.execute("DELETE FROM rate_limits WHERE ip IN ('127.0.0.1', '::1')");
            return null;
          });
        },
      });
      return config;
    },
  },
});
