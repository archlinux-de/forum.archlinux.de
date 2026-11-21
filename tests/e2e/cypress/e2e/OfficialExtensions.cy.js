describe('Official extensions', () => {
  it('offers members private messages', () => {
    const name = 'tester' + Cypress._.sampleSize('abcdefghijklmnopqrstuvwxyz', 16).join('')
    cy.register(name, 'password1234', name + '@archlinux.de')
    cy.visit('/messages')
    cy.get('.MessagesPage').should('be.visible')
  })

})
