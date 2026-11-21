describe('Post', () => {
  it('posts message', () => {
    const userName = 'tester' + Cypress._.sampleSize('abcdefghijklmnopqrstuvwxyz', 16).join('')
    const postTitle = 'Hello World!'
    const postMessage = 'What`s up?'

    cy.register(userName, 'password1234', userName + '@archlinux.de')

    cy.get('button.IndexPage-newDiscussion').click()
    cy.get('.item-discussionTitle input').type(postTitle)
    cy.get('textarea.TextEditor-editor').type(postMessage + ' :)')
    cy.get('.DiscussionComposer-changeTags').click()
    cy.get('.SelectTagListItem-name').click()
    cy.get('button[type=submit]').click()
    cy.get('.item-submit button').click()

    cy.url().should('contain', Cypress.config().baseUrl + '/d/')
    cy.get('h2').should('contain', postTitle)
    cy.get('.Post-body').should('contain', postMessage)
    cy.get('.Post-body img.emoji').should('have.attr', 'src', '/assets/extensions/twemoji-assets/72x72/1f642.png')
    cy.get('.Post-body img.emoji').should(images => {
      expect(images[0].naturalWidth).to.be.greaterThan(0)
      expect(new URL(images[0].src).origin).to.eq(new URL(Cypress.config().baseUrl).origin)
    })
  })
})
