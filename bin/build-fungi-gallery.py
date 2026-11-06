#!/usr/bin/env python3
"""Prepare responsive gallery copies; preserve original downloads."""
from pathlib import Path
import hashlib,json
from PIL import Image,ImageOps
ROOT=Path(__file__).resolve().parents[1]
D=Path.home()/'Downloads'
SOURCES=[
('8512804',ROOT/'assets/preloader-mushroom-desktop.webp','A slender mushroom on a moss-covered forest floor','mushroom-helmling-moss-tap-forest-8512804'),
('7536200',D/'jggrz-growth-7536200_1920.jpg','A group of pale mushrooms rising from moss','growth-forest-mushrooms-macro-7536200'),
('7555696',D/'jggrz-mushrooms-7555696_1920.jpg','Violet mushrooms against a dark woodland background','mushrooms-disc-fungus-fall-moss-7555696'),
('5764816',D/'jggrz-mushrooms-5764816.jpg','Warm light through the gills of orange mushrooms','mushrooms-moss-forest-floor-lamella-5764816'),
('4566638',D/'jggrz-forest-4566638.jpg','Amber mushrooms growing beside a fallen branch','forest-mushrooms-screen-fungus-4566638'),
('7561871',D/'jggrz-mushrooms-7561871_1920.jpg','Delicate pale mushrooms surrounded by green moss','mushrooms-helmetlings-moss-7561871')]
POSITIONS={'8512804':'38% 50%','7536200':'67% 50%','7555696':'43% 50%','5764816':'58% 50%','4566638':'62% 50%','7561871':'38% 50%'}
out=ROOT/'assets/fungi';out.mkdir(exist_ok=True);slides=[]
for identifier,path,alt,slug in SOURCES:
 image=ImageOps.exif_transpose(Image.open(path)).convert('RGB');variants=[]
 for width in [960,1920,2560]:
  if width>image.width:continue
  sample=image.copy();sample.thumbnail((width,width),Image.Resampling.LANCZOS)
  tmp=out/f'{identifier}-{width}.webp';sample.save(tmp,'WEBP',quality=88,method=6)
  digest=hashlib.sha256(tmp.read_bytes()).hexdigest();final=tmp.with_name(f'jggrz-{identifier}-{width}-{digest[:12]}.webp');tmp.rename(final)
  variants.append({'url':'/'+str(final.relative_to(ROOT)),'width':sample.width,'height':sample.height,'sha256':digest,'bytes':final.stat().st_size})
 thumb=image.copy();thumb.thumbnail((240,160),Image.Resampling.LANCZOS);tmp=out/f'{identifier}-thumb.webp';thumb.save(tmp,'WEBP',quality=78,method=6);digest=hashlib.sha256(tmp.read_bytes()).hexdigest();final=tmp.with_name(f'jggrz-{identifier}-thumb-{digest[:12]}.webp');tmp.rename(final)
 slides.append({'id':identifier,'alt':alt,'position':POSITIONS[identifier],'source':'https://pixabay.com/photos/'+slug+'/','photographer':'Jürgen Liebherr','profile':'https://pixabay.com/users/jggrz-7998824/','permission':'Used with direct permission. Copyright remains with Jürgen Liebherr.','variants':variants,'thumbnail':'/'+str(final.relative_to(ROOT)),'original_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'original_dimensions':[image.width,image.height]})
(ROOT/'config/fungi-gallery.json').write_text(json.dumps({'title':'Fungi','photographer':'Jürgen Liebherr','profile':'https://pixabay.com/users/jggrz-7998824/','slides':slides},indent=2,ensure_ascii=False)+'\n')
print('Prepared',len(slides),'photographs;',sum(p.stat().st_size for p in out.glob('*.webp')),'bytes of responsive assets.')
