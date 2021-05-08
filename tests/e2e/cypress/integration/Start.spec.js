describe('Start page', () => {
  it('shows title', () => {
    cy.visit('/')
    cy.contains('h1', 'Arch Linux')
  })
})
