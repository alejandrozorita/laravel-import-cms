<?php

namespace App\\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Requests\BlogRequest;

use App\Models\Blog;
use App\Models\BlogComentario;
use App\Models\BlogSeo;
use App\Models\BlogTag;
use App\Models\Categoria;
use App\Models\Front;
use App\Models\Plataformas;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

use App\Models\EspecialidadMedica;
use App\Models\CategoriaMedica;

use App\Repositories\CategoriaRepo;
use App\Repositories\BlogRepo;
use App\Repositories\BlogTagsRepo;
use App\Repositories\FrontRepo;
use App\Repositories\TutorialRepo;

use Input;


class ImportadorController extends BaseController
{
    public function __construct(CategoriaRepo $categoriaRepo,
                                BlogRepo $blogRepo,
                                FrontRepo $frontRepo,
                                TutorialRepo $tutorialRepo,
                                BlogTagsRepo $blogTagsRepo)
    {
        $this->categoriaRepo = $categoriaRepo;
        $this->blogRepo = $blogRepo;
        $this->blogTagsRepo = $blogTagsRepo;
        $this->frontRepo = $frontRepo;
        $this->tutorialRepo = $tutorialRepo;
    }

   

    /**
     * IMPORTAR ENTRADAS
     */


    /**
     * [tipo_importacion cargamos la vista para elegir el tipo de archivo que vamos a importar]
     * @return [view] [vista para elegir el tipo de archivo a importar]
     */
    public function tipo_importacion(){

        //datos comunes de las vistas
        $datos_vista = $this->cargar_datos();

        //definimos el paso en que nos encotramos
        $datos_vista['paso'] = 'tipo-importacion';

        //tipos de importacion queda definido por el logo de la pagina desde donde se ha exportado el archivo
        $datos_vista['logos-importar-entradas'] = $this->imagenes_importar_entradas();

        return return_vistas('blogs.importar-entradas', $datos_vista); 
    }

    /**
     * Sacamos las imágenes de una carpeta para el tipo de importacion
     * @param  [type] [description]
     * @return array [array con el nombre de las imágenes]
     */
    public function imagenes_importar_entradas()
    {
        //Abrimos el directorio donde están las imágenes
        $directorio = opendir(carpeta_public().'/assets'.version_backend().'/img/logos-importar-entradas');

        //Declaramos el array que vamos a devolver con las imágenes
        $arr_imgagenes_cupones = array();

        //obtenemos un archivo y luego otro sucesivamente
        while ($archivo = readdir($directorio))
        {
            //verificamos si es o no un directorio
            if (!is_dir($archivo) && $archivo != '.DS_Store' && $archivo != 'thumbnail')
            {   
                $nombre_archivo = explode('.', $archivo);
                $arr_imagenes_importar[$nombre_archivo[0]] = $archivo;
            }
        }
        
        return $arr_imagenes_importar;
    }

    /**
     * [importar_archivo cargamos la vista donde vamos a subir el archivo que queremos importar]
     * @param  Request $request [el request define el tipo de importacion que vamos a realizar]
     * @return [view]           [vista donde cargar el archivo]
     */
    public function importar_archivo(Request $request){

        //datos comunes de las vistas
        $datos_vista = $this->cargar_datos();
        
        //obtenemos el array de imagenes que define el tipo de importacion
        $arr_imagenes_importar = $this->imagenes_importar_entradas();

        //si el request que recibimos no se haya entre el array anterior, volvemos a la vista inicial
        if(!isset($arr_imagenes_importar[$request->tipo])){
            return redirect()->route('tipo-importacion');
        }

        //recogemos el tipo de archivo
        $datos_vista['tipo-importacion'] = $request->tipo;

        //definimos el paso en que nos encontramos
        $datos_vista['paso'] = 'importar-archivo';

        return return_vistas('blogs.importar-entradas', $datos_vista);

    }

    /**
     * [importar_entradas_post recogemos, procesamos y guardamos los elementos del archivo que subimos]
     * @param  Request $request [ archivo => file, tipo_importacion => nombre del tipo de importacion que vamos a realizar]
     * @return [type]           [description]
     */
    public function importar_entradas_post(Request $request){

        //datos comunes de las vistas
        $datos_vista = $this->cargar_datos();

        //recogeos el nombre del tipo de importacion
        $tipo_importacion = $request->tipo_importacion;

        //declaramos el metodo que vamos utilizar segun el tipo de importacion
        $metodo = 'cargar_archivo_'.$tipo_importacion;

        //recogemos el array de entradas que nos devuelve el metodo declarado
        $array_entradas = $this->$metodo();

        //guardamos las nuevas entradas en la base de datos
        $guardar_entradas_importadas = $this->guardar_entradas_importadas($array_entradas);

        //definimos el paso en que nos encontramos
        $datos_vista['paso'] = 'resumen-importar-entradas';

        //definimos el numero de las nuevas entradas
        $datos_vista['num_entradas'] = count($array_entradas);

        return return_vistas('blogs.importar-entradas', $datos_vista);
    }

    /**
     * [cargar_archivo_wordpress obtenemos una array de entradas tipo Medssocial desde un archivo XML de Wordpress]
     * @return [array] [array de entradas tipo Medssocial]
     */
    public function cargar_archivo_wordpress(){

        //obtenemos es contenido del archivo xml que hemos subido
        $contenido_archivo = file_get_contents($_FILES['archivo']['tmp_name']);

        //cargamos la informacion del archivo en un objeto SimpleXMLElement
        $xml = simplexml_load_string($contenido_archivo);

        //declaramos el array de entradas
        $entradas = array();

        //recoremos los elementos del objeto SimpleXMLElement que representa un articulo
        foreach($xml->channel->item as $item)
        {
            //declaramos los array para categoria y etiquetas
            $categorias = array();
            $etiquetas = array();

            //recoremos los elementos del objeto SimpleXMLElement que representa una categoria o etiqueta (category)
            foreach($item->category as $category)
            {
                //recogemos las caterias del articulo
                if($category['nicename'] != "uncategorized" && $category['domain'] == "category")
                {
                    $categorias[] = $category;
                }

                //recogemos las etiquetas del articulo
                if($category['nicename'] != "uncategorized" && $category['domain'] == "post_tag")
                {
                    $etiquetas[] = $category;
                }
            }

            //si el articulo no tiene categoria, los guardamo como null
            if(!isset($categorias[0])){
                $categorias[0] = NULL;
            }

            //ejecutamos los archivos de configuracion de los elemento xmlns del archivo xml
            $content = $item->children('http://purl.org/rss/1.0/modules/content/');
            $excerpt = $item->children('http://wordpress.org/export/1.2/excerpt/');
            $wp      = $item->children('http://wordpress.org/export/1.2/');

            //obtenemos la fecha y hora de la publicacion del articulo
            $publicacion = explode(" ",$wp->post_date);

            //cambiamos el contenido de elementos [nombre atributos=value][/nombre], en formato html 
            $contenido = html_entity_decode($content->encoded);
            $contenido = str_replace('[', '<',$contenido);
            $contenido = str_replace(']', '>',$contenido);

            //creamos por cada articulo del archivo xml un entrada tipo Medssocial
            $entradas[] = array(
                "titulo"=>html_entity_decode($item->title),
                "extracto_texto"=>html_entity_decode($excerpt->encoded),
                "contenido"=>$contenido,
                "publicacion"=>$publicacion[0],
                "categoria"=>html_entity_decode($categorias[0]),
                "etiquetas"=>implode(",", $etiquetas),
                "titulo_url"=>normalizar_string($item->title)
            );
        }

        return $entradas;
    }

    /**
     * [guardar_entradas_importadas guardamos las nuevas entradas en la base de datos]
     * @param  [array] $entradas [array de entradas tipo Medssocial]
     * @return [type]           [description]
     */
    public function guardar_entradas_importadas($entradas = null){

        foreach ($entradas as $entrada) {
            
            //por defecto declaramos NULL el categoria_id de las entradas
            $entrada['categoria_id'] = NULL;

            //si hya categorias declarada la guardamos y obtenemos su id
            if(!is_null($entrada['categoria'])){

                //recogemos el nombre de la categoria, en formato slug 
                $categoria_slug = normalizar_string($entrada['categoria']);

                //buscamos el slug en la base de dados
                $categoria_db = Categoria::where('slug',$categoria_slug)->first();

                //si la categoria no exites, la creamos
                if(!$categoria_db){
                    $categoria_new =  new Categoria;
                    $categoria_new->nombre = $entrada['categoria'];
                    $categoria_new->slug   = $categoria_slug;
                    $categoria_new->save();

                    $categoria_db = $categoria_new;
                }

                //recoremos el id de la categorias
                $entrada['categoria_id'] = $categoria_db->id;

            }

            //todas las entras que importamos son borradores
            $entrada['borrador'] = true;

            //guardamos la nueva entrada en la base de datos
            $entrada_new = Blog::create($entrada);
            $entrada['blog_id'] = $entrada_new->id;

            //guardamos las etiquetas de la entrada 
            BlogTag::where('blog_id',$entrada_new->id)->delete();

            $tags_objeto = explode(",",$entrada['etiquetas']);

            foreach ($tags_objeto as $tags => $value) {
                $db['blog_id'] = $entrada_new->id;
                $db['tag'] = $value;
                BlogTag::insert($db);
            }

            //guardamos las configuracion seo de la entrada;
            $entrada_seo =  BlogSeo::create($entrada);
    
        }
    }


    /**
     * FIN IMPORTAR ENTRADAS
     */
}
