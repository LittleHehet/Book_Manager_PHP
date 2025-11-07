# 📚 BOOK MANAGER PRO
Versión 5/11/2025 Curso EIF402 - Administración de Bases de Datos

👥Autores (Grupo2-3pm):
   -Alexia Alvarado Alfaro	   402580319
   -Kendra Artavia Caballero	402580003
   -Randy Nuñez Vargas	      119100297
   -Katherine Jara Arroyo	   402650268
   -Jose Carballo Morales	   119060186


---

## 🌟 Descripción general
**Book Manager Pro** es una aplicación web ligera que permite registrar, editar y eliminar libros de una biblioteca personal o institucional.
Su propósito es capturar las bases de la administración de las bases de datos, como su implementación en diferentes entornos digitales.  
Está desarrollado en **PHP** con base de datos **SQLite**, con un diseño adaptable y responsivo para diferentes tipos de dispositivos y soporte de cambio de interfaz.
El sistema incluye un **instalador automático**, interfaz moderna de modo oscuro con **testilos “cozy café”**, y soporte para **modo claro** con persistencia entre páginas.
Este proyecto fue desarrollado como parte de un trabajo académico con enfoque en buenas prácticas de desarrollo, diseño visual y seguridad básica (CSRF, validaciones y control de errores).

---

## 🧩 Objetivos del proyecto
- Uso de bases de datos en entornos remotos.
- Implementar instalación automática de bases de datos.
- Enseñanza de diferentes herramientas consiguientes al desarrollo web y bases de datos

---

## 🚀 Características principales

✅ **Gestión completa de libros**
- Agregar, editar y eliminar registros fácilmente.
- Campos: título, autor, año y género.

✅ **Base de datos integrada**
- Utiliza **SQLite**, sin necesidad de instalación adicional de MySQL.
- Configuración automática mediante `install.php`.

✅ **Diseño adaptable (responsive)**
- Interfaz moderna en tonos cálidos (modo oscuro).
- Tema claro disponible con selector persistente entre páginas.

✅ **Seguridad y validación**
- Protección **CSRF** en formularios.
- Validaciones de entrada en servidor y cliente.
- Mensajes de estado y alertas informativas.

✅ **Auto-instalación**
- Si no se detectan las tablas necesarias, redirige a `install.php` para crear la base de datos y las tablas automáticamente.

---

## 🧩 Tecnologías utilizadas

| Componente | Descripción |
|-------------|-------------|
| **PHP 8+** | Lógica del servidor y manejo de sesiones. |
| **SQLite** | Base de datos ligera integrada. |
| **HTML5 / CSS3 / JS** | Interfaz adaptable y scripts interactivos. |
| **Flexbox / Grid** | Maquetación moderna y responsiva. |
| **LocalStorage** | Persistencia del tema (oscuro/claro). |

---

## ⚙️ Instalación

### 🔹 Requisitos previos
- Windows 10/11 o Linux(Funcionalidad comprobada en Ubuntu)
- PHP 8 o superior
- Sqlite 3.5 o superior
- Herramienta de Servidor local. Se recomienda **XAMPP**.
- Navegador actualizado (Chrome, Edge o Firefox).

### 🔹 Pasos
1. Clona o copia el proyecto en la carpeta donde vayas a instanciar el proyecto:
   ```bash
   C:\xampp\htdocs\book-manager-pro
Inicia Apache desde XAMPP.

Accede desde tu navegador:

Copiar código
http://localhost/book-manager-pro/
El sistema verificará si existe la base de datos:

Si no, ejecutará automáticamente el instalador (install.php).

Se crearán las tablas necesarias (books, users).

¡Listo! 🎉 Ya puedes inciar sesión y registrar tus libros favoritos.

🎨 Interfaz y modo de color
Modo oscuro (por defecto): tonos cálidos tipo madera, enfocado en comodidad visual.

Modo claro: tonos azulados suaves.

El usuario puede cambiar el tema desde el botón 🌙 / 🌞 en la barra superior.

La preferencia se guarda automáticamente en localStorage y se mantiene entre páginas.

🧑‍💻 Estructura del proyecto
pgsql
Copiar código
book-manager-pro/
│
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── theme.js
│
├── config/
│   ├── bootstrap.php
│   ├── check_data.php
│   ├── database_test.php
│   ├── setup.php
│   └── database.php
│
├── models/
│   ├── Book.php
│   └── User.php
|
├── data/
│   └── database.sqlite
│
├── test/
│   ├── BookTest.php
│   ├── bootstrap.php
│   └── UserTest.php
│
│
├── add_from_google.php
├── auth_guard.php
├── categories.php
├── check_data.php
├── composer.json
├── composer.lock
├── login.php
├── logout.php
├── phpunit.xml
├── rate.php
├── recover.php
├── reports.php
|
├── install.php
├── index.php
├── add.php
├── edit.php
├── delete.php
├── setup.php
└── README.md


🛡️ Licencia
Proyecto de uso académico — Universidad Nacional de Costa Rica (UNA).
Libre de uso educativo y sin fines comerciales. 