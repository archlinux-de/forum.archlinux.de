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
        event.preventDefault()
        const img = this._createImg()
        img.onerror = () => {
          placeHolder.innerHTML = '<strong class="error">Bild konnte nicht geladen werden!</strong>'
        }
        img.onload = () => {
          placeHolder.style.width = `${img.width}px`
          placeHolder.style.height = `${img.height}px`
          setTimeout(() => placeHolder.replaceWith(img), 200)
        }
      })
      this.shadowRoot.appendChild(placeHolder);
    }

    _createPlaceholder() {
      const hostname = new URL(this.getAttribute('src')).hostname
      const div = document.createElement('div')
      div.innerHTML = `<span>Bild von <strong>${hostname}</strong> laden</span>`
      return div
    }

    _createStyle() {
      const style = document.createElement('style')
      style.innerHTML = `
        div {
            height:150px;
            width:100%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: sans-serif;
            font-size: 14px;
            background-color: #ededed;
            color: #808080;
            cursor: pointer;
            transition: width .2s ease-in-out, height .2s ease-in-out;
        }
        img {
            max-width: 100%;
        }
        .error {
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
