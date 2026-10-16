export type CartItem = { product_id: number; variant_id: string; quantity: number };
export function useShoppingCart() {
  const items = useState<CartItem[]>('shopping-cart', () => []);
  const ready = useState('shopping-cart-ready', () => false);
  onMounted(() => {
    if (!ready.value) {
      try {
        const saved = JSON.parse(localStorage.getItem('3r-cart-v1') || '[]');
        items.value = Array.isArray(saved) ? saved.filter((x: any) => Number.isInteger(x.product_id) && x.product_id > 0 && typeof x.variant_id === 'string' && x.variant_id.length < 100 && Number.isInteger(x.quantity) && x.quantity > 0 && x.quantity <= 100000).map((x: CartItem) => ({ product_id: x.product_id, variant_id: x.variant_id, quantity: x.quantity })) : [];
      } catch { items.value = []; }
      ready.value = true;
    }
  });
  watch(items, (value) => { if (import.meta.client && ready.value) { try { localStorage.setItem('3r-cart-v1', JSON.stringify(value)); } catch {} } }, { deep: true });
  function add(product_id: number, variant_id: string, quantity: number) {
    if (!Number.isInteger(quantity) || quantity < 1) return;
    const existing = items.value.find(x => x.product_id === product_id && x.variant_id === variant_id);
    if (existing) existing.quantity += quantity;
    else items.value.push({ product_id, variant_id, quantity });
  }
  function remove(index: number) { items.value.splice(index, 1); }
  function clear() { items.value = []; }
  const count = computed(() => items.value.reduce((sum, x) => sum + x.quantity, 0));
  return { items, ready, count, add, remove, clear };
}
