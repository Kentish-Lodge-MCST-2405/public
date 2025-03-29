module Jekyll
  class ListingGenerator < Generator
    def generate(site)
      site.pages.each do |page|
        if page.url =~ %r{/(policies|bylaws|templates)/$}
          dir = page.url.split('/')[1]
          page.data['files'] = Dir.glob("#{dir}/*.md").map { |f| File.basename(f, '.md') }
        end
      end
    end
  end
end
