/*
| El único JS propio del panel.
|
| Alpine no se importa ni se instala: lo trae Livewire y lo expone en `window.Alpine`, así que
| aquí sólo se registra un componente en `alpine:init`, que se dispara antes de que Alpine
| arranque. Cero dependencias nuevas (§5).
*/

document.addEventListener('alpine:init', () => {
    /*
     * Desplegable con buscador. Sustituye al `<select>` nativo en todo el panel: con el
     * maestro entero delante, elegir un comercio o una ruta en una lista de decenas de
     * opciones se hacía a base de scroll.
     *
     * **Las opciones las pinta Blade, no este objeto.** Aquí sólo se esconden y se resaltan
     * `<li>` que ya existen en el DOM. Es a propósito: si la lista viviera en JS habría que
     * mantenerla sincronizada cuando Livewire vuelve a renderizar —al crear una ruta nueva,
     * por ejemplo— y el desplegable se quedaría enseñando la lista vieja. El valor elegido
     * tampoco se guarda aquí: se escribe en un input oculto con el `wire:model` del llamante,
     * y la etiqueta que se ve cerrado la pinta el servidor.
     */
    Alpine.data('searchableSelect', () => ({
        open: false,
        query: '',
        index: 0,

        toggle() {
            this.open ? this.close() : this.show()
        },

        show() {
            this.open = true
            this.query = ''

            this.$nextTick(() => {
                this.$refs.query?.focus()
                this.filter()
            })
        },

        close() {
            this.open = false
        },

        /* Sin tildes y en minúsculas: buscar «chamberi» tiene que encontrar «Chamberí». */
        normalize(text) {
            return (text ?? '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim()
        },

        options() {
            return Array.from(this.$refs.list?.querySelectorAll('[data-option]') ?? [])
        },

        matching() {
            return this.options().filter((option) => !option.hidden)
        },

        filter() {
            const query = this.normalize(this.query)

            this.options().forEach((option) => {
                option.hidden = query !== '' && !this.normalize(option.dataset.search).includes(query)
            })

            if (this.$refs.empty) {
                this.$refs.empty.hidden = this.matching().length > 0
            }

            this.highlight(0)
        },

        /* Cuál está marcada con el teclado. El resaltado es una clase y no estado de Alpine
           porque los `<li>` son del servidor: así no hay dos sitios que puedan discrepar. */
        highlight(position) {
            const matching = this.matching()

            if (matching.length === 0) {
                return
            }

            this.index = Math.max(0, Math.min(position, matching.length - 1))

            matching.forEach((option, n) => {
                option.classList.toggle('bg-slate-100', n === this.index)
                option.setAttribute('aria-selected', n === this.index ? 'true' : 'false')
            })

            matching[this.index]?.scrollIntoView({ block: 'nearest' })
        },

        move(step) {
            if (! this.open) {
                this.show()

                return
            }

            this.highlight(this.index + step)
        },

        choose() {
            this.matching()[this.index]?.click()
        },

        /*
         * Lo elegido se escribe en el input oculto y se avisa a Livewire a mano, porque el
         * cambio no lo hizo una persona tecleando en él.
         *
         * Los dos eventos cubren los tres modificadores sin dispararse dos veces: `wire:model`
         * y `wire:model.live` escuchan `input`, y `wire:model.blur` escucha `blur`.
         */
        pick(value) {
            const input = this.$refs.input

            input.value = value
            input.dispatchEvent(new Event('input', { bubbles: true }))
            input.dispatchEvent(new Event('blur', { bubbles: true }))

            this.close()
            this.$refs.button?.focus()
        },
    }))
})
