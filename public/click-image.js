(() => {
  class ClickImage extends HTMLElement {
    constructor() {
      super();
      this.attachShadow({mode: "open"});
    }

    connectedCallback() {
      this.shadowRoot.appendChild(this._createStyle());
      const placeHolder = this._createPlaceholder()
      placeHolder.addEventListener('click', (event) => {
        placeHolder.classList.add('loading')
        event.preventDefault()
        const img = this._createImg()
        img.onerror = () => {
          placeHolder.classList.remove('loading')
          placeHolder.classList.add('error')
        }
        img.onload = () => {
          placeHolder.style.width = `min(${img.width}px, 100%)`
          placeHolder.style.height = 'auto'
          placeHolder.style.aspectRatio = `${img.width} / ${img.height}`
          setTimeout(() => placeHolder.replaceWith(img), 200)
        }
      }, {once: true})
      this.shadowRoot.appendChild(placeHolder);
    }

    _createPlaceholder() {
      const hostname = new URL(this.getAttribute('src')).hostname
      const div = document.createElement('div')
      div.innerHTML = `
        <span class="placeholder-message">Bild von <strong>${hostname}</strong> laden</span>
        <span class="error-message">
          Bild konnte nicht von
          <a href="${this.getAttribute('src')}" target="_blank" rel="nofollow">${hostname}</a>
          geladen werden!
        </span>
      `
      return div
    }

    _createStyle() {
      const style = document.createElement('style')
      style.innerHTML = `
        div {
          padding: 8px;
          box-sizing: border-box;
          border: 1px solid #fff;
          height: 150px;
          width: 100%;
          max-width: 100%;
          display: inline-flex;
          justify-content: center;
          align-items: center;
          background-color: #ededed;
          cursor: pointer;
          transition: width .2s ease-in-out, height .2s ease-in-out;
        }
        img {
          vertical-align: middle;
          max-width: 100%;
        }
        span {
          font-family: sans-serif;
          font-size: 14px;
          color: #808080;
        }
        a {
          color: unset;
        }
        .error-message {
          display: none;
        }
        .error .placeholder-message {
          display: none;
        }
        .error .error-message {
          display: inline-block;
        }
        .loading {
          cursor: wait;
        }
        .loading span {
          display: none;
        }
        .loading::before {
          content: ' '
        }
        .error {
          cursor: unset;
        }
        .error span {
          color: #B72A2A;
        }
      `

      return style
    }

    _createImg() {
      const img = document.createElement('img')
      if (this.getAttribute('height') > 0) {
        img.height = this.getAttribute('height')
      }
      if (this.getAttribute('width') > 0) {
        img.height = this.getAttribute('width')
      }
      if (this.getAttribute('alt').length > 0) {
        img.alt = this.getAttribute('alt')
      }
      if (this.getAttribute('title').length > 0) {
        img.title = this.getAttribute('title')
      }
      img.src = this.getAttribute('src')
      return img
    }
  }

  customElements.define('click-img', ClickImage);
})()
