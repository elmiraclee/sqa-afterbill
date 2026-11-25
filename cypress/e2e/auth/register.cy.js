describe('Registration', () => {
  const fill = ({ name, email, password, confirm = password, terms = true }) => {
    cy.visit('/auth/register.php');
    if (name) cy.get('#full_name').type(name);
    if (email) cy.get('#email').type(email);
    if (password) cy.get('#password').type(password, { log: false });
    if (confirm) cy.get('#confirm_password').type(confirm, { log: false });
    if (terms) cy.get('#terms').check();
  };

  it('creates an unverified account and asks the user to check their inbox', () => {
    cy.testEmail('new').then((email) => {
      fill({ name: 'Maria Santos', email, password: 'Str0ng!Bills' });
      cy.get('#registerForm button[type=submit]').click();

      cy.get('.alert-success').should('contain', 'Check your inbox').and('contain', email);
      cy.task('db:getUser', email).then((row) => {
        expect(row, 'user row').to.not.be.null;
        expect(row.full_name).to.eq('Maria Santos');
        expect(row.email_verified_at, 'not verified yet').to.be.null;
      });
    });
  });

  it('rejects an email that is already registered', () => {
    cy.createUser().then((user) => {
      fill({ name: 'Someone Else', email: user.email, password: 'Str0ng!Bills' });
      cy.get('#registerForm button[type=submit]').click();
      cy.get('.alert-error').should('contain', 'already registered');
    });
  });

  it('does not create an account when the server rejects a weak password', () => {
    cy.testEmail('weak').then((email) => {
      cy.visit('/auth/register.php');
      // Submit the raw form, skipping register.js, to test the PHP validation.
      cy.get('#registerForm').then(($form) => {
        $form.find('#full_name').val('Weak Pass');
        $form.find('#email').val(email);
        $form.find('#password').val('password');
        $form.find('#confirm_password').val('password');
        $form.find('#terms').prop('checked', true);
        HTMLFormElement.prototype.submit.call($form[0]);
      });
      cy.get('.alert-error').should('exist');
      cy.task('db:getUser', email).should('be.null');
    });
  });
});
