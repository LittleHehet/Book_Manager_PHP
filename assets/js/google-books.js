// google-books.js — búsqueda simple en la API pública de Google Books
(function(){
  const qEl = document.getElementById('gb-query');
  const btn = document.getElementById('gb-search');
  const out = document.getElementById('gb-results');
  const countNum = document.querySelector('.counter-compact .count-num');
  const csrf = document.querySelector('meta[name="bm-csrf"]')?.getAttribute('content') || '';

  if (!qEl || !btn || !out) return;

  const renderItem = (v) => {
    const info = v.volumeInfo || {};
    const title = info.title || '—';
    const authors = (info.authors || []).join(', ');
    const year = (info.publishedDate || '').slice(0,4);
    const genre = (info.categories || []).join(', ');
    const thumb = info.imageLinks?.thumbnail || '';

    const card = document.createElement('div');
    card.className = 'card';

    const row = document.createElement('div');
    row.style.display = 'flex'; row.style.gap = '12px'; row.style.alignItems = 'flex-start';

    const imgWrap = document.createElement('div');
    imgWrap.style.flex = '0 0 auto'; imgWrap.style.width = '72px';
    if (thumb) {
      const imgEl = document.createElement('img');
      imgEl.src = thumb; imgEl.alt = `Portada de ${title}`;
      imgEl.style.width = '72px'; imgEl.style.height = '100px'; imgEl.style.objectFit = 'cover'; imgEl.style.borderRadius = '6px';
      imgWrap.appendChild(imgEl);
    } else {
      const no = document.createElement('div');
      no.className = 'empty-icon'; no.textContent = 'No image'; no.style.width = '72px'; no.style.height = '100px'; no.style.display = 'grid'; no.style.placeItems = 'center';
      imgWrap.appendChild(no);
    }

    const body = document.createElement('div'); body.style.flex = '1';
    const h = document.createElement('h3'); h.textContent = title; h.style.margin = '0 0 6px 0'; h.className = 'book-title';
    const meta = document.createElement('div'); meta.className = 'text-muted'; meta.textContent = authors + (year ? ' · ' + year : '');

    const form = document.createElement('form'); form.method = 'post'; form.action = 'add_from_google.php';
    form.style.marginTop = '8px';
    const inputs = { title: title, author: authors, year: year, genre: genre, categories: genre };
    for (const k in inputs) {
      const inp = document.createElement('input'); inp.type = 'hidden'; inp.name = k; inp.value = inputs[k] || '';
      form.appendChild(inp);
    }
    const csrfInp = document.createElement('input'); csrfInp.type='hidden'; csrfInp.name='csrf'; csrfInp.value = csrf; form.appendChild(csrfInp);
    const addBtn = document.createElement('button'); addBtn.type = 'submit'; addBtn.className = 'btn btn-primary'; addBtn.textContent = 'Agregar';
    form.appendChild(addBtn);

    body.appendChild(h); body.appendChild(meta); body.appendChild(form);

    row.appendChild(imgWrap); row.appendChild(body);
    card.appendChild(row);
    return card;
  };

  const setLoading = (is) => {
    btn.disabled = is;
    btn.textContent = is ? 'Buscando…' : 'Buscar';
  };

  btn.addEventListener('click', async () => {
    const q = qEl.value.trim();
    if (!q) return;
    setLoading(true);
    out.innerHTML = '';
    try {
      // Llamada pública sin API key (bajo cuota, adecuada para prototipo)
      const res = await fetch('https://www.googleapis.com/books/v1/volumes?q=' + encodeURIComponent(q) + '&maxResults=20');
      if (!res.ok) throw new Error('Error al consultar Google Books');
      const json = await res.json();
      const items = json.items || [];
      countNum.textContent = String(items.length);
      if (!items.length) out.innerHTML = '<div class="empty-state"><div class="empty-icon">🔍</div><h3>No se encontraron resultados</h3></div>';
      items.forEach(v => out.appendChild(renderItem(v)));
    } catch (e) {
      out.innerHTML = `<div class="alert alert-error">Error: ${e.message}</div>`;
    } finally {
      setLoading(false);
    }
  });

  // También permitir Enter en el input
  qEl.addEventListener('keydown', (ev)=>{ if(ev.key === 'Enter'){ ev.preventDefault(); btn.click(); } });
})();
