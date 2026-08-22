import base64

readme_content = """# Escritorio Docente (WebOS)

Entorno de escritorio virtual (*WebOS*) ligero, modular y ejecutable en navegador, diseñado para la gestión de recursos didácticos, distribución documental y ejecución de microaplicaciones en entornos educativos.

---

## Características Principales

* **Autenticación Híbrida y Privacidad:**
  * **Modo Invitado:** Almacenamiento 100% local en el navegador mediante `localStorage`.
  * **Sincronización Cloud:** Integración con la API de Google Drive para portabilidad de perfiles y ficheros.
  * **Panel de Administración:** Control centralizado de plantillas de escritorio, configuración de accesos y gestión de feeds.

* **Gestión Documental ("Public"):**
  * Repositorio compartido síncrono en servidor.
  * Manipulación directa mediante eventos *drag-and-drop*.
  * Generación y copia automática de enlaces externos acortados vía TinyURL sin exponer la estructura interna del servidor.

* **Lanzador Dinámico de Aplicaciones:**
  * Carga dinámica de herramientas locales (`.html` y `.php`) ubicadas en `/Aplicaciones`.
  * Abstracción y enmascaramiento estético de extensiones técnicas.
  * Ejecución encapsulada mediante el manejador nativo de ventanas flotantes.

* **Sistema de Widgets en Toasts:**
  * Panel desplegable de microherramientas ubicadas en `/widgets`.
  * Visualización en contenedores flotantes asíncronos tipo *toast*.

* **Sindicación de Noticias (RSS):**
  * Monitorización periódica de feeds corporativos.
  * Desplegables colapsables para lectura de contenido completo.
  * Persistencia de estado de descarte y lectura en local para evitar notificaciones redundantes.

* **Soporte Táctil y Personalización:**
  * Compatibilidad completa con iPadOS y dispositivos táctiles (Touch Punch).
  * Selector rotativo de fondos de pantalla con persistencia.
  * Modo a pantalla completa (*Fullscreen API*).
  * Persistencia dimensional y posicional de ventanas con detección de límites del *viewport*.

---

## Estructura de Directorios

```text
├── index.html              # Núcleo de la aplicación cliente (WebOS)
├── api_public.php          # Pasarela para gestión documental y TinyURL
├── api_apps.php            # Escáner dinámico de aplicaciones locales
├── api_widgets.php         # Escáner de microherramientas y miniaturas
├── api_rss.php             # Pasarela y parser de canales RSS
├── upload_admin.php        # Gestor de subida de recursos para administradores
├── save_admin.php          # Serialización de configuraciones base
├── rss_manager.php         # Interfaz de gestión de avisos y feeds
├── Aplicaciones/           # Directorio de aplicaciones .html / .php
├── widgets/                # Directorio de widgets (.html/.php y .png)
├── public/                 # Directorio de almacenamiento público compartido
└── uploads/                # Configuraciones del sistema y ficheros admin

