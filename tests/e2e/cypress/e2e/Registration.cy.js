describe('Registration', () => {
  it('registers user', () => {
    const userName = 'tester' + Math.random().toString().replace('.', '')
    cy.register(userName, 'password1234', userName + '@archlinux.de')
  })
})
