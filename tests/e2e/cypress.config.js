const fs = require('fs')
const path = require('path')

module.exports = {
  fixturesFolder: false,
  screenshotOnRunFailure: false,
  trashAssetsBeforeRuns: false,
  video: false,
  numTestsKeptInMemory: 0,
  e2e: {
    setupNodeEvents(on, config) {
      on('task', {
        latestConfirmationLink() {
          const logDirectory = '/app/storage/logs'
          const confirmationLinks = fs.readdirSync(logDirectory)
            .filter(file => /^flarum-.*\.log$/.test(file))
            .sort()
            .flatMap(file => fs.readFileSync(path.join(logDirectory, file), 'utf8').match(/\/confirm\/[^/\s"']+/g) || [])

          if (confirmationLinks.length === 0) {
            throw new Error('No email confirmation link found in Flarum logs')
          }

          return confirmationLinks.at(-1)
        },
      })
    },
  },
  component: {
    setupNodeEvents(on, config) {},
    specPattern: '**/*.cy.{js,jsx,ts,tsx}',
  },
}
