import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    redirect: '/webhooks',
  },
  {
    path: '/webhooks',
    name: 'WebhooksList',
    component: () => import('@/views/WebhooksList.vue'),
  },
  {
    path: '/webhooks/new',
    name: 'WebhookCreate',
    component: () => import('@/views/WebhookEdit.vue'),
  },
  {
    path: '/webhooks/:id',
    name: 'WebhookEdit',
    component: () => import('@/views/WebhookEdit.vue'),
    props: true,
  },
  {
    path: '/webhooks/:id/logs',
    name: 'WebhookLogs',
    component: () => import('@/views/WebhookLogs.vue'),
    props: true,
  },
  {
    path: '/logs',
    name: 'LogsList',
    component: () => import('@/views/LogsList.vue'),
  },
  {
    path: '/queue',
    name: 'Queue',
    component: () => import('@/views/QueueView.vue'),
  },
  {
    path: '/tokens',
    name: 'ApiTokens',
    component: () => import('@/views/ApiTokensView.vue'),
  },
  {
    path: '/settings',
    name: 'Settings',
    component: () => import('@/views/SettingsView.vue'),
  },
  {
    path: '/endpoints',
    name: 'EndpointsList',
    component: () => import('@/views/EndpointsList.vue'),
  },
  {
    path: '/endpoints/new',
    name: 'EndpointCreate',
    component: () => import('@/views/EndpointEdit.vue'),
  },
  {
    path: '/endpoints/:id',
    name: 'EndpointEdit',
    component: () => import('@/views/EndpointEdit.vue'),
    props: true,
  },
  {
    path: '/endpoints/:id/payloads',
    name: 'EndpointPayloads',
    component: () => import('@/views/EndpointPayloads.vue'),
    props: true,
  },
  {
    path: '/endpoints/:id/logs',
    name: 'EndpointLogs',
    component: () => import('@/views/EndpointLogs.vue'),
    props: true,
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

export default router
