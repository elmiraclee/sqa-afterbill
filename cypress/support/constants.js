// Test accounts all use this domain so db:reset never touches real users.
// example.com is reserved (RFC 2606): verification emails sent to it go nowhere.
module.exports = { TEST_DOMAIN: 'cypress.example.com' };
