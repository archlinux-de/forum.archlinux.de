describe('Cache headers', () => {
  it('caches guest API responses', () => {
    cy.request('/api').should((response) => {
      expect(response.headers['x-cache']).to.equal('MISS')
    })

    cy.request('/api').should((response) => {
      expect(response.headers['x-cache']).to.equal('HIT')
    })
  })

  it('does not set cache header on forum HTML', () => {
    cy.request('/').should((response) => {
      expect(response.headers['cache-control']).to.contain('no-store')
    })
  })
})
