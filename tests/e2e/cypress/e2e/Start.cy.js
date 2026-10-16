describe('Start page', () => {
  it('shows Arch Linux branding', () => {
    cy.visit('/')
    cy.get('nav img[alt="Arch Linux"]').should('be.visible')
  })

  it('switches to native dark mode', () => {
    cy.visit('/')
    cy.get('.ThemeSwitcher .Dropdown-toggle').click()
    cy.get('.ThemeSwitcher-option--dark').click()
    cy.get('html').should('have.attr', 'data-theme', 'dark')
    cy.reload()
    cy.get('html').should('have.attr', 'data-theme', 'dark')
  })
})
