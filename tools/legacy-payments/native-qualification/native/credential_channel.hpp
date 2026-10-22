// Shared constrained password-only channel. Requires protected bridge helpers.
#pragma once
namespace paymenter_qa { namespace {
File credential_file(const std::string& path,Owner owner) {
    canonical(path);auto slash=path.rfind('/');
    Fd parent(directory_fd(path.substr(0,slash),owner,false));
    Fd fd(openat(parent.fd,path.substr(slash+1).c_str(),O_RDONLY|O_NOFOLLOW|O_CLOEXEC|O_NONBLOCK));
    struct stat before,after;
    guard(fstat(fd.fd,&before)==0 && S_ISREG(before.st_mode) && before.st_uid==owner.uid && before.st_gid==owner.gid &&
        before.st_nlink==1 && ((before.st_mode&07777)==0400 || (before.st_mode&07777)==0600) &&
        before.st_size>0 && before.st_size<=1024,"reader-credential-custody");
    auto raw=bounded(fd.fd,1024);
    guard(fstat(fd.fd,&after)==0 && stamp(before)==stamp(after) && raw.size()==static_cast<size_t>(before.st_size),"reader-credential-changed");
    return {raw,stamp(before)};
}
std::string credential_password(const std::string& raw) {
    // This qualified channel is a simple existing [client]/password file. Never
    // invoke a defaults parser which could load includes, plugins or init SQL.
    auto trim=[](const std::string& value) {
        auto first=value.find_first_not_of(" \t"),last=value.find_last_not_of(" \t");
        return first==std::string::npos?std::string():value.substr(first,last-first+1);
    };
    std::istringstream input(raw);std::string line,password;bool section=false,found=false;
    while(std::getline(input,line)) {
        if(!line.empty() && line.back()=='\r')line.pop_back();
        line=trim(line);
        if(line.empty() || line[0]=='#' || line[0]==';')continue;
        if(!section){guard(line=="[client]","reader-credential-format");section=true;continue;}
        auto equals=line.find('=');
        guard(!found && equals!=std::string::npos && trim(line.substr(0,equals))=="password","reader-credential-format");
        password=trim(line.substr(equals+1));found=true;
        guard(!password.empty() && password.size()<=256,"reader-credential-format");
        if(password[0]=='\'' || password[0]=='\"') {
            guard(password.size()>=3 && password.back()==password[0],"reader-credential-format");
            password=password.substr(1,password.size()-2);
        }
        for(unsigned char c:password)guard(c>32 && c<127 && c!='\\' && c!='\'' && c!='\"' && c!='#' && c!=';',"reader-credential-format");
    }
    guard(section && found,"reader-credential-format");return password;
}
}
}
