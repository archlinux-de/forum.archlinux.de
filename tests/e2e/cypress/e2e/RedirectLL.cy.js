describe('RedirectLL', () => {
  it('redirects Postings', () => {
    const userName = 'tester' + Math.random().toString().replace('.', '')
    const postTitle = 'Hello World!'
    const postMessage = 'What`s up?'

    cy.register(userName, 'password1234', userName + '@archlinux.de')

    cy.get('button.IndexPage-newDiscussion').click()
    cy.get('.item-discussionTitle input').type(postTitle)
    cy.get('textarea.TextEditor-editor').type(postMessage)
    cy.get('a.DiscussionComposer-changeTags').click()
    cy.get('.SelectTagListItem-name').click()
    cy.get('button[type=submit]').click()
    cy.get('.item-submit button').click()
    cy.url().should('contain', Cypress.config().baseUrl + '/d/')

    cy.url().then(urlString =>{
      const discussionId = urlString.replace(/.*\/d\/([0-9]+)-.*/, '$1')
      cy.visit(`${Cypress.config().baseUrl}/?page=Postings;thread=${discussionId}`)
      cy.url().should('contain', `${Cypress.config().baseUrl}/d/${discussionId}-`)
    })
  })
})
