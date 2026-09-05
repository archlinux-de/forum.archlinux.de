describe('Start page', () => {
  it('shows Arch Linux branding', () => {
    cy.visit('/')
    cy.get('nav img[alt="Arch Linux"]').should('be.visible')
  })
})
