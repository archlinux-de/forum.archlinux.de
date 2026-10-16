describe('Registration', () => {
  it('registers user', () => {
    const userName = 'tester' + Cypress._.sampleSize('abcdefghijklmnopqrstuvwxyz', 16).join('')
    cy.register(userName, 'password1234', userName + '@archlinux.de')
  })
})
