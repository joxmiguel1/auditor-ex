# Auditor EX 🚀

**Auditor EX** es una suite de análisis SEO y rendimiento web construida en PHP y JavaScript puro. Diseñada para ser ligera, rápida y con una experiencia de usuario moderna (Dark/Light mode).

![Auditor EX Preview](jox3.png)

## ✨ Características

* **Auditoría Local (PHP):** Análisis instantáneo de cabeceras, meta etiquetas, estructura H1 y atributos ALT.
* **Core Web Vitals (JS):** Integración asíncrona con la API de Google PageSpeed Insights (LCP, CLS, TBT).
* **Detección de CDN:** "Rayos X" vía DNS para detectar Cloudflare, AWS, etc., incluso si están ocultos.
* **UX Moderna:** Interfaz centrada tipo "Hero", transiciones en acordeón y Modo Oscuro persistente.
* **Puntuación Inteligente:** Algoritmo ponderado (0-100) priorizando velocidad e infraestructura.

## 🛠️ Instalación

1.  Clona este repositorio o descarga los archivos.
2.  Sube el contenido a tu servidor web (requiere soporte PHP + cURL).
3.  Abre `index.php` en tu editor de código.
4.  Busca la línea: `const googleApiKey = 'TU_API_KEY_AQUI';`
5.  Reemplaza `TU_API_KEY_AQUI` con tu clave gratuita de Google Cloud Console.

## 📦 Requisitos

* PHP 7.4 o superior.
* Extensión PHP cURL habilitada.
* Extensión PHP DOM habilitada.

## 📄 Licencia

Este proyecto es de código abierto.