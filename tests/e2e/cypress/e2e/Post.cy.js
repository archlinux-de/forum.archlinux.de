describe('Post', () => {
  it('posts message', () => {
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
    cy.get('h2').should('contain', postTitle)
    cy.get('.Post-body').should('contain', postMessage)
  })
})
