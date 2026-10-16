// Synthetic dev-only HTTP upload and persistence verification.
const { request } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');

async function main() {
  assert(process.env.DEMO_PASSWORD, 'Set the synthetic dev DEMO_PASSWORD');
  const services = JSON.parse(execFileSync('docker', ['compose', 'config', '--format', 'json'], { encoding: 'utf8' }));
  assert.equal(services.name, 'r3-dev', 'This verification only restarts r3-dev');
  const api = await request.newContext({ baseURL: 'http://localhost:3180', extraHTTPHeaders: { Accept: 'application/json' } });
  const guest = await request.newContext({ baseURL: 'http://localhost:3180', extraHTTPHeaders: { Accept: 'application/json' } });
  const csrf = async () => (await (await api.get('/api/v1/auth/csrf')).json()).csrf_token;
  try {
    const login = await api.post('/api/v1/auth/login', { headers: { 'X-CSRF-TOKEN': await csrf() }, data: { phone: '0900000001', password: process.env.DEMO_PASSWORD } });
    assert.equal(login.status(), 200);
    const content = `Synthetic persistence acceptance ${Date.now()}\n`;
    const uploaded = await api.post('/api/v1/resources', {
      headers: { 'X-CSRF-TOKEN': await csrf() },
      multipart: { title: '合成持久化驗收', category: '驗收', file: { name: 'persistence.txt', mimeType: 'text/plain', buffer: Buffer.from(content) } },
    });
    assert(uploaded.ok(), `Upload failed: ${uploaded.status()}`);
    const resource = await uploaded.json();
    const path = `/api/v1/files/${resource.file_id}/download`;
    assert.equal((await guest.get(path)).status(), 403, 'Anonymous download must be denied');
    assert.equal(await (await api.get(path)).text(), content);
    execFileSync('docker', ['compose', '-p', 'r3-dev', 'restart', 'postgres', 'redis', 'backend', 'worker', 'scheduler', 'frontend', 'web'], { stdio: 'inherit' });
    let healthy = false;
    for (let i = 0; i < 45; i++) {
      try { if ((await guest.get('/api/v1/health')).ok()) { healthy = true; break; } } catch {}
      await new Promise(resolve => setTimeout(resolve, 1000));
    }
    assert(healthy, 'Health did not recover');
    assert.equal(await (await api.get(path)).text(), content, 'Uploaded file must survive restart');
    const resources = await (await api.get('/api/v1/resources')).json();
    assert(resources.data.some(row => row.id === resource.id), 'Resource metadata must survive restart');
    assert.equal((await guest.get(path)).status(), 403);
    console.log('PASS: private upload, access denial, file bytes, metadata and login session survived r3-dev restart.');
  } finally {
    await api.dispose();
    await guest.dispose();
  }
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
