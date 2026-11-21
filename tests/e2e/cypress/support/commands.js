Cypress.Commands.add('register', (username, password, email) => {
  cy.visit('/')
  cy.get('.item-signUp button').click()
  cy.wait(500)
  cy.get('input[name=username]').type(username)
  cy.get('input[name=email]').type(email)
  cy.get('input[name=password]').type(password)
  cy.intercept('POST', '/register').as('register')
  cy.get('button[type=submit]').click()
  cy.wait('@register').then(({ response }) => {
    expect(response.statusCode, JSON.stringify(response.body)).to.eq(201)
  })

  cy.get('.item-session .username').should('contain', username)

  cy.task('latestConfirmationLink').then(confirm => {
    cy.visit(confirm)
    cy.get('button[type=submit]').click()
    cy.visit('/')
  })
})
