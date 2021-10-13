Cypress.Commands.add('register', (username, password, email) => {
  cy.visit('/')
  cy.get('.item-signUp button').click()
  cy.wait(500)
  cy.get('input[name=username]').type(username)
  cy.get('input[name=email]').type(email)
  cy.get('input[name=password]').type(password)
  cy.get('button[type=submit]').click()

  cy.get('.item-session .username').should('contain', username)

  cy.exec('grep -Eo "/confirm/[^/]+" /app/storage/logs/flarum-*.log | tail -1').then(confirm => {
    cy.visit(confirm.stdout)
    cy.get('button[type=submit]').click()
    cy.visit('/')
  })
})
