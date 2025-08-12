# Plugin OptimizadorImagenes

Optimiza imágenes de MyFiles y guarda logs por fecha en FacturaScripts.

realizado el 05-08-2025
v1.1 el 06-08-2025 arreglado


Compatibilidad de Rutas: Uso de DIRECTORY_SEPARATOR para Windows y Linux.

Tiempo de Ejecución: Límite de tiempo desactivado con set_time_limit(0).

Corrección de Codificación: La línea ini_set se ha movido al lugar correcto.

Mantenimiento de Proporción: La lógica de redimensionamiento ahora conserva el aspecto original de las imágenes para si son tamaño vertical que no pierdan el aspecto.

Contador de Imágenes: Se ha añadido una variable para mostrar el total de imágenes en la interfaz de usuario.

Mensaje de Carga: Se ha incluido el código HTML y JavaScript para mostrar un mensaje de "por favor, espere".