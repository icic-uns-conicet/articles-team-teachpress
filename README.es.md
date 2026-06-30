# OpenAlex Team Publications

Plugin de WordPress que integra el Custom Post Type `team` (de [TLP Team](https://wordpress.org/plugins/tlp-team/)) con la API de [OpenAlex](https://openalex.org/) para importar y gestionar automáticamente las publicaciones académicas de los miembros de un equipo de investigación, almacenándolas en [teachPress](https://wordpress.org/plugins/teachpress/).

## 📋 Tabla de Contenidos

- [Características](#-características)
- [Requisitos](#-requisitos)
- [Instalación](#-instalación)
- [Configuración](#-configuración)
- [Uso](#-uso)
- [Arquitectura](#-arquitectura)
- [API de OpenAlex](#-api-de-openalex)
- [Sincronización](#-sincronización)
- [Frontend](#-frontend)
- [Herramientas de Migración](#-herramientas-de-migración)
- [Solución de Problemas](#-solución-de-problemas)
- [Licencia](#-licencia)

## ✨ Características

### 🔍 Importación Inteligente
- **Deduplicación automática** por OpenAlex Work ID y DOI
- **Mapeo completo de metadatos**: título, autores, DOI, journal, volumen, número, páginas, abstract, año
- **Reconstrucción de abstracts** desde el índice invertido de OpenAlex
- **Generación automática de claves BibTeX**

### 👥 Gestión de Autores
- **Relaciones autor-publicación** individuales en teachPress
- **Enlaces inteligentes**: Los miembros del equipo aparecen enlazados a sus perfiles en las listas de autores
- **Deduplicación de autores** reutilizando entradas existentes en teachPress
- **Soporte para múltiples autores** con formato estándar (Apellido, Iniciales)

### ⚡ Procesamiento en Background
- **Cola de trabajos asíncrona** usando Action Scheduler
- **Sincronización sin bloquear** la interfaz de administración
- **Estado de sincronización** en tiempo real por miembro
- **Prevención de trabajos duplicados**

### 🎨 Frontend Integrado
- **Inyección automática** de publicaciones en páginas `single-team.php`
- **Agrupación por año** con diseño responsive
- **Estilos personalizables** con CSS inline
- **Enlaces a DOI** y URLs de publicaciones
- **Tipos de publicación** con badges de colores (artículo, libro, conferencia, tesis, etc.)

### 🛠️ Administración
- **Configuración centralizada** de API key y email
- **Sincronización automática** con intervalos configurables (manual, cada hora, diario, semanal)
- **Columnas personalizadas** en el listado de miembros del equipo
- **Quick Edit** para OpenAlex ID
- **Filtros por estado de sincronización**
- **Ocultar/mostrar publicaciones** individuales
- **Herramienta de migración** de IDs de autores

### 🚀 Optimización
- **Sistema de caché** con transients de WordPress (12 horas)
- **Consultas optimizadas** con índices apropiados
- **Rate limiting** respetuoso con la API de OpenAlex
- **Paginación automática** para autores con muchas publicaciones

## 📦 Requisitos

- **WordPress** 5.0 o superior
- **PHP** 7.4 o superior
- **Plugin teachPress** activo (para gestión de publicaciones)
- **Plugin TLP Team** activo (para el Custom Post Type `team`)
- **Action Scheduler** (incluido en `vendor/action-scheduler/`)

## 🔧 Instalación

### 1. Clonar o Descargar el Repositorio

```bash
cd wp-content/plugins/
git clone https://github.com/icic-uns-conicet/articles-scraper.git openalex-team-publications
```

O descarga el ZIP y descomprímelo en `wp-content/plugins/openalex-team-publications/`

### 2. Activar el Plugin

1. Ve a **Plugins** en el panel de WordPress
2. Busca **OpenAlex Team Publications**
3. Haz clic en **Activar**

### 3. Verificar Dependencias

Asegúrate de que los siguientes plugins estén activos:
- ✅ teachPress
- ✅ TLP Team

## ⚙️ Configuración

### Página de Configuración

Ve a **Team → Configuración OpenAlex** en el menú de administración.

#### API de OpenAlex

| Campo | Descripción | Obligatorio |
|-------|-------------|-------------|
| **API Key** | Clave de API de OpenAlex para autenticación y mayor rate limit. Obtén una en [openalex.org](https://openalex.org/) | Opcional |
| **Email para User-Agent** | Email incluido en el User-Agent para mejor rate-limiting y cumplimiento de políticas | Recomendado |

#### General

| Campo | Descripción | Valores |
|-------|-------------|---------|
| **Sincronización automática** | Frecuencia de sincronización automática | Manual, Cada hora, Dos veces al día, Diario, Semanal |
| **Máximo de publicaciones por miembro** | Límite de publicaciones a importar por miembro en cada sincronización | 10 - 1000 (predeterminado: 200) |

### Configurar OpenAlex ID para Miembros

1. Ve a **Team → Todos los miembros**
2. Edita un miembro
3. En el campo **OpenAlex ID**, ingresa el ID del autor en OpenAlex
   - Ejemplo: `https://openalex.org/A1234567890` o simplemente `A1234567890`
4. Guarda los cambios

**¿Cómo encontrar el OpenAlex ID?**
- Busca al autor en [openalex.org](https://openalex.org/)
- Copia la URL del perfil del autor
- El ID es la última parte de la URL (ej: `A1234567890`)

## 📖 Uso

### Sincronización Manual

#### Desde el Listado de Miembros

1. Ve a **Team → Todos los miembros**
2. Pasa el cursor sobre un miembro
3. Haz clic en **Sincronizar con OpenAlex** en las acciones rápidas
4. El trabajo se encolará y procesará en background

#### Sincronización Masiva

1. Selecciona múltiples miembros usando los checkboxes
2. En el menú desplegable **Acciones en lote**, selecciona **Sincronizar con OpenAlex**
3. Haz clic en **Aplicar**

### Ver Estado de Sincronización

En el listado de miembros, las siguientes columnas muestran el estado:

- **Estado OpenAlex**: idle, en cola, procesando, completado, error
- **Última sincronización**: Fecha y hora de la última sincronización exitosa
- **Publicaciones**: Número total de publicaciones importadas

### Gestionar Publicaciones

#### Ocultar/Mostrar Publicaciones

1. Ve a **Team → Publicaciones**
2. Pasa el cursor sobre una publicación
3. Haz clic en **Ocultar** o **Mostrar** en las acciones rápidas

Las publicaciones ocultas no aparecerán en el frontend pero seguirán en la base de datos.

#### Ver Publicaciones de un Miembro

1. Ve a **Team → Publicaciones**
2. Usa el filtro **Miembro del equipo** para ver solo las publicaciones de un miembro específico

### Frontend

Las publicaciones se muestran automáticamente en las páginas individuales de cada miembro (`single-team.php`).

**Características del frontend:**
- ✅ Agrupación por año (más reciente primero)
- ✅ Badges de colores por tipo de publicación
- ✅ Enlaces a DOI cuando están disponibles
- ✅ Autores con enlaces a perfiles de miembros del equipo
- ✅ Diseño responsive
- ✅ Caché de 12 horas para optimizar rendimiento

## 🏗️ Arquitectura

```
openalex-team-publications/
│
├── team-teachpress-integration.php    # Archivo principal del plugin
│
├── includes/
│   └── class-helpers.php              # Utilidades compartidas (formato, mapeo, DB, caché)
│
├── core/
│   ├── class-openalex-api.php         # Comunicación con api.openalex.org
│   ├── class-teachpress-import.php    # Deduplicación + inserción en teachPress
│   └── class-job-queue.php            # Cola de trabajos en background (Action Scheduler)
│
├── admin/
│   ├── class-settings.php             # Página de configuración
│   ├── class-admin-columns.php        # Columnas personalizadas, Quick Edit, filtros
│   ├── class-admin-sync.php           # Handler de sincronización (admin-post)
│   └── class-publications-page.php    # Submenú y vistas de administración
│
├── frontend/
│   └── class-single-team.php          # Inyección en single-team.php (tlp-team)
│
└── vendor/
    └── action-scheduler/              # Dependencia: Action Scheduler
```

### Flujo de Sincronización

```
1. Usuario inicia sincronización (manual o automática)
   ↓
2. OpenAlex_Job_Queue::enqueue_member_sync() encola el trabajo
   ↓
3. Action Scheduler ejecuta OpenAlex_Job_Queue::process_sync() en background
   ↓
4. OpenAlex_API::fetch_works() obtiene publicaciones de OpenAlex (paginación automática)
   ↓
5. OpenAlex_TeachPress_Import::sync_member() procesa cada publicación:
   - Verifica duplicados por OpenAlex Work ID
   - Verifica duplicados por DOI
   - Mapea campos de OpenAlex a teachPress
   - Inserta/actualiza en teachPress
   - Guarda relaciones autor-publicación
   ↓
6. Se actualiza el estado de sincronización del miembro
   ↓
7. Se limpia la caché de publicaciones del miembro
```

## 🌐 API de OpenAlex

### Endpoints Utilizados

El plugin utiliza el endpoint `/works` de la API de OpenAlex:

```
GET https://api.openalex.org/works
  ?filter=author.id:{openalex_author_id}
  &per-page=200
  &cursor=*
  &select=id,title,type,publication_year,doi,authorships,biblio,primary_location,abstract_inverted_index
```

### Autenticación

- **API Key**: Opcional pero recomendada para mayor rate limit
- **User-Agent**: Incluye email para mejor rate-limiting
- **Rate Limit**:
  - Sin API key: 100,000 requests/día
  - Con API key: 100,000 requests/día + prioridad

### Paginación

El plugin usa **cursor-based pagination** para manejar autores con muchas publicaciones:
- Máximo 10 páginas por sincronización
- 200 publicaciones por página
- Total máximo: 2,000 publicaciones por miembro (configurable)

## 🔄 Sincronización

### Sincronización Automática

Configurable en **Team → Configuración OpenAlex**:

| Intervalo | Descripción |
|-----------|-------------|
| **Manual** | Solo sincronización manual |
| **Cada hora** | `wp_schedule_event()` con recurrencia `hourly` |
| **Dos veces al día** | Recurrencia `twicedaily` |
| **Diario** | Recurrencia `daily` (predeterminado) |
| **Semanal** | Recurrencia `weekly` |

### Sincronización Manual

#### Desde la Interfaz

1. **Acción rápida**: Pasa el cursor sobre un miembro → **Sincronizar con OpenAlex**
2. **Acción masiva**: Selecciona miembros → **Acciones en lote** → **Sincronizar con OpenAlex** → **Aplicar**

#### Programáticamente

```php
// Sincronizar un miembro específico
$result = OpenAlex_Job_Queue::enqueue_member_sync($post_id);

// Verificar estado
$status = OpenAlex_Job_Queue::get_member_status($post_id);
```

### Deduplicación

El plugin implementa un sistema de deduplicación en dos niveles:

1. **Por OpenAlex Work ID**: Busca en `teachpress_pub_meta` si ya existe una publicación con ese ID
2. **Por DOI**: Si no hay Work ID, busca por DOI en `teachpress_pub`

Si se encuentra un duplicado:
- Se actualiza el `openalex_work_id` si es necesario
- Se asegura la relación miembro-publicación
- Se omite la creación de una nueva publicación

### Estados de Sincronización

| Estado | Descripción |
|--------|-------------|
| `idle` | Sin actividad |
| `queued` | En cola, esperando procesamiento |
| `running` | Procesando publicaciones |
| `completed` | Sincronización completada exitosamente |
| `failed` | Error durante la sincronización |

## 🎨 Frontend

### Inyección Automática

El plugin detecta automáticamente cuando se carga una página `single-team.php` e inyecta el bloque de publicaciones usando JavaScript al final del contenedor principal.

**Selectores de contenedor (en orden de prioridad):**
1. `.tlp-single-container`
2. `.tlp-single-detail`
3. `article.type-team`
4. `main`
5. `#content`
6. `.site-content`

### Estructura HTML

```html
<div class="openalex-publications">
  <h3 class="openalex-publications__title">
    Publicaciones <span class="openalex-publications__count">(42)</span>
  </h3>

  <div class="openalex-publications__year-group">
    <h4 class="openalex-publications__year">2024</h4>
    <ul class="openalex-publications__list">
      <li class="openalex-publications__item">
        <span class="openalex-pub-type openalex-pub-type--article">Artículo</span>
        <span class="openalex-pub-title">
          <a href="https://doi.org/10.1234/example">Título de la publicación</a>
        </span>
        <span class="openalex-pub-authors">
          <a href="/team/juan-perez">Perez J.P.</a>, Garcia M., et al.
        </span>
        <em class="openalex-pub-journal">Journal Name</em>
        <a class="openalex-pub-doi" href="https://doi.org/10.1234/example">DOI: 10.1234/example</a>
      </li>
    </ul>
  </div>
</div>
```

### Personalización de Estilos

Los estilos se inyectan inline y pueden sobrescribirse en tu tema:

```css
/* Ejemplo: Cambiar colores de badges */
.openalex-pub-type--article {
  background: #custom-color;
  color: #custom-text;
}

/* Ejemplo: Ajustar espaciado */
.openalex-publications__item {
  padding: 1em 0;
}
```

### Tipos de Publicación

| Tipo OpenAlex | Tipo teachPress | Badge | Color |
|---------------|-----------------|-------|-------|
| `article`, `journal-article` | `article` | Artículo | Verde |
| `book-chapter` | `inbook` | Capítulo | Rosa |
| `book`, `edited-book` | `book` | Libro | Rosa oscuro |
| `proceedings-article`, `conference-paper` | `inproceedings` | Conferencia | Naranja |
| `dissertation`, `thesis` | `phdthesis` | Tesis | Púrpura |
| `preprint` | `unpublished` | Preprint | Gris |
| `report` | `techreport` | Informe | Azul |
| Otros | `misc` | Misc | Gris |

## 🔧 Herramientas de Migración

### Migrar IDs de Autores

**Ubicación**: Team → Configuración OpenAlex → Herramientas

**Propósito**: Recorre las publicaciones ya importadas y guarda el `openalex_author_id` de cada autoría individual.

**¿Cuándo usar?**
- Después de actualizar el plugin a una versión que soporte enlaces por ID
- Cuando hay dos miembros del equipo con el mismo apellido y los enlaces no funcionan correctamente
- Para mejorar la precisión de los enlaces en listas de autores

**Seguridad**:
- ✅ Seguro ejecutar múltiples veces
- ✅ Solo afecta publicaciones que aún no tienen el dato
- ✅ No modifica datos existentes

**Resultado**:
- Publicaciones procesadas
- Relaciones de autor actualizadas
- Errores (si los hay)

## 🐛 Solución de Problemas

### El plugin no activa

**Problema**: Error al activar el plugin

**Solución**:
1. Verifica que teachPress esté activo
2. Verifica que TLP Team esté activo
3. Revisa los logs de error de PHP
4. Asegúrate de tener PHP 7.4 o superior

### Las publicaciones no se importan

**Problema**: Al sincronizar, no se importan publicaciones

**Posibles causas**:
1. **OpenAlex ID incorrecto**: Verifica que el ID sea válido en [openalex.org](https://openalex.org/)
2. **Sin publicaciones**: El autor no tiene publicaciones indexadas en OpenAlex
3. **Error de API**: Revisa los logs en `wp-content/debug.log` (si `WP_DEBUG_LOG` está activo)

**Solución**:
```php
// Activar logging en wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Los enlaces de autores no funcionan

**Problema**: Los miembros del equipo no aparecen enlazados en las listas de autores

**Solución**:
1. Ejecuta la **Migración de IDs de autores** en Team → Configuración OpenAlex → Herramientas
2. Verifica que todos los miembros tengan su OpenAlex ID configurado
3. Limpia la caché de transients:
   ```php
   // Ejecutar una vez en functions.php o WP-CLI
   wp transient delete --all
   ```

### La sincronización se queda en "En cola"

**Problema**: El estado no cambia de "queued"

**Posibles causas**:
1. **Action Scheduler no está funcionando**: Verifica que el cron de WordPress esté activo
2. **Trabajo bloqueado**: Puede haber un trabajo anterior que falló

**Solución**:
```bash
# Ver trabajos pendientes con WP-CLI
wp action-scheduler list --hook=openalex_sync_member_background

# Cancelar trabajos pendientes
wp action-scheduler cancel --hook=openalex_sync_member_background
```

O usa el plugin **Action Scheduler** para ver y gestionar trabajos.

### Las publicaciones no aparecen en el frontend

**Problema**: Las publicaciones se importaron pero no se muestran en `single-team.php`

**Posibles causas**:
1. **Caché**: Las publicaciones están en caché
2. **Publicaciones ocultas**: Las publicaciones están marcadas como ocultas
3. **Template incorrecto**: El tema no usa `single-team.php`

**Solución**:
```php
// Limpiar caché de un miembro específico
OpenAlex_Helpers::clear_member_publications_cache($post_id);

// O limpiar toda la caché
wp transient delete --all
```

### Error "teachPress no está activo"

**Problema**: La sincronización falla con este mensaje

**Solución**:
1. Verifica que teachPress esté instalado y activo
2. Verifica que las clases `TP_Publications` y `TP_Authors` existan
3. Revisa la versión de teachPress (debe ser compatible)

## 📊 Estructura de Base de Datos

El plugin utiliza las siguientes tablas de teachPress:

### `wp_teachpress_pub`
Tabla principal de publicaciones.

### `wp_teachpress_pub_meta`
Metadatos de publicaciones. El plugin agrega:
- `openalex_work_id`: ID del work en OpenAlex
- `openalex_member_id`: ID del post del miembro del equipo
- `openalex_author_id_{author_id}`: ID del autor en OpenAlex
- `openalex_hidden`: Si la publicación está oculta (1/0)

### `wp_teachpress_authors`
Autores registrados en teachPress.

### `wp_teachpress_rel_pub_auth`
Relaciones muchos-a-muchos entre publicaciones y autores.

## 🔒 Seguridad

- ✅ Validación y sanitización de todos los inputs
- ✅ Nonces en todos los formularios
- ✅ Verificación de permisos (`manage_options`, `edit_posts`)
- ✅ Escape de outputs (`esc_html`, `esc_attr`, `esc_url`)
- ✅ Preparación de consultas SQL (`$wpdb->prepare`)
- ✅ No almacena credenciales sensibles en el código

## 🤝 Contribuir

Las contribuciones son bienvenidas. Para contribuir:

1. Fork el repositorio
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📄 Licencia

Este proyecto está bajo la Licencia GPL v2 o posterior.

## 👤 Autor

**Carlos Lorenzetti**
ICIC-UNS-CONICET

## 🙏 Agradecimientos

- [OpenAlex](https://openalex.org/) por proporcionar acceso abierto a datos académicos
- [teachPress](https://wordpress.org/plugins/teachpress/) por el sistema de gestión de publicaciones
- [TLP Team](https://wordpress.org/plugins/tlp-team/) por el Custom Post Type para equipos
- [Action Scheduler](https://actionscheduler.org/) por el sistema de cola de trabajos

## 📞 Soporte

Para reportar problemas o solicitar características:
- [GitHub Issues](https://github.com/icic-uns-conicet/articles-scraper/issues)

---

**Versión**: 4.1
**Última actualización**: Junio 2026