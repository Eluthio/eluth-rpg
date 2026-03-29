import { createApp } from 'vue'
import RpgLauncher from './RpgLauncher.vue'
import PlayerWindow from './PlayerWindow.vue'
import GmWindow from './GmWindow.vue'

window.__EluthPlugins = window.__EluthPlugins || {}
window.__EluthPlugins['rpg'] = {
    zones: ['channel-header'],
    component: RpgLauncher,

    // Message renderer: show a compact roll card when a roll is shared to chat
    messageRenderer: {
        pattern: /\/rpg-roll\/[0-9a-f-]{36}/,
        render(url) {
            const id = url.match(/\/rpg-roll\/([0-9a-f-]{36})/)?.[1]
            if (!id) return null
            return `<span class="rpg-roll-card" data-rpg-roll-id="${id}">🎲 Roll result</span>`
        },
    },

    // Context menu: invite user to campaign
    contextMenuItems: [
        {
            label: '🎲 Invite to Campaign',
            when: ({ isSelf }) => !isSelf,
            action({ memberId, author, channelId }) {
                document.dispatchEvent(new CustomEvent('rpg:invite', {
                    detail: { memberId, author, channelId },
                }))
            },
        },
    ],

    // Bootstrap: detect popup query params and mount overlay
    async bootstrap(api) {
        const params     = new URLSearchParams(window.location.search)
        const gmSession  = params.get('rpg_gm')
        const playerSession = params.get('rpg_player')

        if (gmSession) {
            const container = document.createElement('div')
            container.id = 'rpg-gm-root'
            document.body.appendChild(container)
            createApp(GmWindow, {
                sessionId: gmSession,
                authToken: api.authToken,
                apiBase:   api.apiBase,
            }).mount('#rpg-gm-root')
            return
        }

        if (playerSession) {
            const container = document.createElement('div')
            container.id = 'rpg-player-root'
            document.body.appendChild(container)
            createApp(PlayerWindow, {
                sessionId: playerSession,
                authToken: api.authToken,
                apiBase:   api.apiBase,
            }).mount('#rpg-player-root')
        }
    },
}
