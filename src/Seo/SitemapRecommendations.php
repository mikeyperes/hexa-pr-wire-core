<?php
namespace HexaPrWire\Core\Seo;
use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\Sitemaps\RecommendationRegistry;
final class SitemapRecommendations implements ModuleInterface {
    public function register(): void {
        add_filter(RecommendationRegistry::FILTER,[self::class,'recommend'],30,2);
    }
    public static function recommend(array $rows,string $site_type=''): array {
        // Bug: a domain literal gated the source publication's sitemap recommendations.
        // Fix: use the site type already configured in Hexa WP Core's sitemap registry.
        // Why this way: the source publication retains coverage when its URL changes.
        $site_type = '' !== $site_type ? $site_type : (string) get_option( 'hws_site_type', '' );
        if ( 'news_outlet' !== $site_type ) {
            return $rows;
        }
        foreach(['press-release','publication','article','organization','resource','product'] as $name){
            $id='post_type_'.$name;
            if(!isset($rows[$id])){
                $rows[$id]=['id'=>$id,'label'=>ucwords(str_replace('-',' ',$name)),'kind'=>'post_type','name'=>$name,'url'=>home_url('/'.$name.'-sitemap.xml'),'setting'=>'pt_'.$name.'_sitemap','current'=>false,'available'=>false];
            }
            $rows[$id]['recommended']=true;
            $rows[$id]['recommended_by']=['Hexa PR Wire Core'];
        }
        return $rows;
    }
}
