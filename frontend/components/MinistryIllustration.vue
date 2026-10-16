<script setup lang="ts">
defineProps<{ kind: 'door' | 'people' | 'growth' | 'home' }>();
</script>
<template>
  <svg viewBox="0 0 240 200" fill="none" aria-hidden="true" focusable="false">
    <circle cx="120" cy="98" r="78" fill="currentColor" opacity=".055" />
    <g stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
      <g v-if="kind === 'door'">
        <path d="M72 168V81a48 48 0 0 1 96 0v87M57 168h126M87 168V83a33 33 0 0 1 66 0v85" />
        <path d="M102 167V88l40-14v93M130 119v7M120 21V9M68 35l-9-10M172 35l9-10M47 79H33M193 79h14" opacity=".65" />
        <path d="M113 180h14M104 192h32" />
      </g>
      <g v-else-if="kind === 'people'">
        <circle cx="82" cy="71" r="21" /><circle cx="158" cy="71" r="21" />
        <path d="M44 163v-33a36 36 0 0 1 36-36h4c17 0 29 12 36 23 7-11 19-23 36-23h4a36 36 0 0 1 36 36v33M64 164v-35M176 164v-35" />
        <path d="m92 127 28 19 28-19M92 145l28 18 28-18M112 36l8 7 8-7" />
        <path d="M37 89h-9M203 89h9M120 22v-9" opacity=".6" />
      </g>
      <g v-else-if="kind === 'growth'">
        <path d="M120 164v-55M120 121c-30 0-49-17-49-44 30 0 49 16 49 44ZM120 105c0-33 22-55 55-55 0 33-22 55-55 55Z" />
        <path d="m83 89 27 24m49-47-29 29M50 137c27-8 49-2 70 12 21-14 43-20 70-12v35c-25-7-48-2-70 12-22-14-45-19-70-12v-35Z" />
        <circle cx="80" cy="40" r="9" opacity=".65" /><path d="M188 102h10M193 97v10" opacity=".65" />
      </g>
      <g v-else>
        <path d="m48 103 72-59 72 59M63 92v80h114V92M107 172v-37h26v37M77 115h16v17H77M147 115h16v17h-16M44 172h152" />
        <path d="M120 106s-20-11-20-24c0-12 15-15 20-4 5-11 20-8 20 4 0 13-20 24-20 24Z" />
        <path d="M49 61v-9m-5 4h10M188 51v-9m-5 4h10M120 25v-9" opacity=".65" />
      </g>
    </g>
  </svg>
</template>
