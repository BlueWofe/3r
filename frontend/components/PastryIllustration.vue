<script setup lang="ts">
defineProps<{ flavor?: string }>();
</script>
<template>
  <figure class="pastry-illustration">
    <svg viewBox="0 0 480 280" role="img" :aria-label="flavor === 'taro' ? '芋香蛋黃酥示意插畫' : '紅豆蛋黃酥示意插畫'">
      <rect width="480" height="280" rx="20" fill="#f3ebdf" />
      <ellipse cx="240" cy="212" rx="170" ry="24" fill="#dcd0bc" />
      <ellipse cx="240" cy="198" rx="170" ry="24" fill="#fffdf7" />
      <path d="M90 177C87 108 115 79 164 80C214 81 240 114 235 177Z" fill="#dba049" />
      <ellipse cx="163" cy="175" rx="73" ry="24" fill="#bf7f30" />
      <path d="M101 159C107 109 128 92 166 93C198 94 219 116 224 158" fill="#efbe68" />
      <path d="M257 180C251 132 283 96 329 100C369 103 399 137 394 183Z" fill="#e2a953" />
      <path d="M257 178C267 139 278 117 320 113C349 133 359 155 357 194Z" fill="#fff0cb" />
      <path d="M267 177C278 145 288 134 317 128C337 142 344 162 342 187Z" :fill="flavor === 'taro' ? '#b695bb' : '#794b40'" />
      <circle cx="308" cy="159" r="24" fill="#e8a021" />
      <circle cx="308" cy="159" r="16" fill="#f2bb48" />
      <g fill="#5c4637">
        <ellipse cx="146" cy="106" rx="2" ry="4" transform="rotate(-30 146 106)" />
        <ellipse cx="162" cy="100" rx="2" ry="4" />
        <ellipse cx="179" cy="108" rx="2" ry="4" transform="rotate(30 179 108)" />
        <ellipse cx="165" cy="117" rx="2" ry="4" transform="rotate(45 165 117)" />
      </g>
    </svg>
    <figcaption>產品示意插畫・非實品照片</figcaption>
  </figure>
</template>
<style scoped>
.pastry-illustration { margin: 0; width: 100%; }
svg { display: block; width: 100%; max-height: 360px; }
figcaption { padding: 8px 12px; color: var(--muted); font-size: 12px; text-align: center; }
</style>
